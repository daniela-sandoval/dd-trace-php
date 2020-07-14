#include "dispatch.h"

#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "arrays.h"
#include "compat_string.h"
#include "ddtrace.h"
#include "ddtrace_string.h"
#include "debug.h"

// avoid Older GCC being overly cautious over {0} struct initializer
#pragma GCC diagnostic ignored "-Wmissing-field-initializers"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

extern inline void ddtrace_dispatch_copy(ddtrace_dispatch_t *dispatch);
extern inline void ddtrace_dispatch_release(ddtrace_dispatch_t *dispatch);

static ddtrace_dispatch_t *_dd_find_function_dispatch(HashTable *ht, zval *fname) {
    return ddtrace_hash_find_ptr_lc(ht, Z_STRVAL_P(fname), Z_STRLEN_P(fname));
}

static ddtrace_dispatch_t *_dd_find_method_dispatch(zend_class_entry *class, zval *fname TSRMLS_DC) {
    if (!fname || !Z_STRVAL_P(fname)) {
        return NULL;
    }
    HashTable *class_lookup = NULL;

#if PHP_VERSION_ID < 70000
    const char *class_name = class->name;
    size_t class_name_length = class->name_length;
#else
    const char *class_name = ZSTR_VAL(class->name);
    size_t class_name_length = ZSTR_LEN(class->name);
#endif

    class_lookup = ddtrace_hash_find_ptr_lc(DDTRACE_G(class_lookup), class_name, class_name_length);
    if (class_lookup) {
        ddtrace_dispatch_t *dispatch = _dd_find_function_dispatch(class_lookup, fname);
        if (dispatch) {
            return dispatch;
        }
    }

    return class->parent ? _dd_find_method_dispatch(class->parent, fname TSRMLS_CC) : NULL;
}

ddtrace_dispatch_t *ddtrace_find_dispatch(zend_class_entry *scope, zval *fname TSRMLS_DC) {
    return scope ? _dd_find_method_dispatch(scope, fname TSRMLS_CC)
                 : _dd_find_function_dispatch(DDTRACE_G(function_lookup), fname);
}

#if PHP_VERSION_ID >= 70000
static void dispatch_table_dtor(zval *zv) {
    zend_hash_destroy(Z_PTR_P(zv));
    efree(Z_PTR_P(zv));
}
#else
static void dispatch_table_dtor(void *zv) {
    HashTable *ht = *(HashTable **)zv;
    zend_hash_destroy(ht);
    efree(ht);
}
#endif

void ddtrace_dispatch_init(TSRMLS_D) {
    if (!DDTRACE_G(class_lookup)) {
        ALLOC_HASHTABLE(DDTRACE_G(class_lookup));
        zend_hash_init(DDTRACE_G(class_lookup), 8, NULL, (dtor_func_t)dispatch_table_dtor, 0);
    }

    if (!DDTRACE_G(function_lookup)) {
        ALLOC_HASHTABLE(DDTRACE_G(function_lookup));
        zend_hash_init(DDTRACE_G(function_lookup), 8, NULL, (dtor_func_t)ddtrace_class_lookup_release_compat, 0);
    }
}

void ddtrace_dispatch_destroy(TSRMLS_D) {
    if (DDTRACE_G(class_lookup)) {
        zend_hash_destroy(DDTRACE_G(class_lookup));
        FREE_HASHTABLE(DDTRACE_G(class_lookup));
        DDTRACE_G(class_lookup) = NULL;
    }

    if (DDTRACE_G(function_lookup)) {
        zend_hash_destroy(DDTRACE_G(function_lookup));
        FREE_HASHTABLE(DDTRACE_G(function_lookup));
        DDTRACE_G(function_lookup) = NULL;
    }
}

void ddtrace_dispatch_reset(TSRMLS_D) {
    if (DDTRACE_G(class_lookup)) {
        zend_hash_clean(DDTRACE_G(class_lookup));
    }
    if (DDTRACE_G(function_lookup)) {
        zend_hash_clean(DDTRACE_G(function_lookup));
    }
}

static HashTable *_get_lookup_for_target(zval *class_name TSRMLS_DC) {
    HashTable *overridable_lookup = NULL;
    if (class_name && DDTRACE_G(class_lookup)) {
#if PHP_VERSION_ID < 70000
        // downcase the class name before lookup as class names are case insensitive.
        zval *class_name_prev = class_name;
        MAKE_STD_ZVAL(class_name);
        ZVAL_STRINGL(class_name, Z_STRVAL_P(class_name_prev), Z_STRLEN_P(class_name_prev), 1);
        ddtrace_downcase_zval(class_name);
        overridable_lookup =
            ddtrace_hash_find_ptr(DDTRACE_G(class_lookup), Z_STRVAL_P(class_name), Z_STRLEN_P(class_name));
        if (!overridable_lookup) {
            overridable_lookup = ddtrace_new_class_lookup(class_name TSRMLS_CC);
        }
        zval_ptr_dtor(&class_name);
        class_name = class_name_prev;
#else
        ddtrace_downcase_zval(class_name);
        overridable_lookup = zend_hash_find_ptr(DDTRACE_G(class_lookup), Z_STR_P(class_name));
        if (!overridable_lookup) {
            overridable_lookup = ddtrace_new_class_lookup(class_name TSRMLS_CC);
        }
#endif
    } else {
        overridable_lookup = DDTRACE_G(function_lookup);
    }

    return overridable_lookup;
}

zend_bool ddtrace_trace(zval *class_name, zval *function_name, zval *callable, uint32_t options TSRMLS_DC) {
    HashTable *overridable_lookup = _get_lookup_for_target(class_name TSRMLS_CC);
    if (overridable_lookup == NULL) {
        return FALSE;
    }

    ddtrace_dispatch_t dispatch;
    memset(&dispatch, 0, sizeof(ddtrace_dispatch_t));
    if (callable != NULL) {
        dispatch.callable = *callable;
        zval_copy_ctor(&dispatch.callable);
    } else {
        ZVAL_NULL(&dispatch.callable);
    }

#if PHP_VERSION_ID < 70000
    ZVAL_STRINGL(&dispatch.function_name, Z_STRVAL_P(function_name), Z_STRLEN_P(function_name), 1);
#else
    ZVAL_COPY(&dispatch.function_name, function_name);
#endif
    ddtrace_downcase_zval(&dispatch.function_name);  // method/function names are case insensitive in PHP
    dispatch.options = options;

    if (ddtrace_dispatch_store(overridable_lookup, &dispatch)) {
        return TRUE;
    } else {
        ddtrace_dispatch_dtor(&dispatch);
        return FALSE;
    }
}

zend_bool ddtrace_hook_callable(ddtrace_string class_name, ddtrace_string function_name, ddtrace_string callable,
                                uint32_t options TSRMLS_DC) {
    HashTable *overridable_lookup;
    ddtrace_dispatch_t dispatch;
    memset(&dispatch, 0, sizeof(ddtrace_dispatch_t));
    dispatch.options = options;
    DDTRACE_STRING_ZVAL_L(&dispatch.function_name, function_name);
    if (callable.ptr) {
        DDTRACE_STRING_ZVAL_L(&dispatch.callable, callable);
    } else {
        ZVAL_NULL(&dispatch.callable);
    }

    if (class_name.ptr) {
        zval z_class_name;
        // class name handling in get_lookup involves another copy as well as downcasing
        // TODO: we should avoid doing that
        DDTRACE_STRING_ZVAL_L(&z_class_name, class_name);
        overridable_lookup = _get_lookup_for_target(&z_class_name TSRMLS_CC);
        zval_dtor(&z_class_name);
    } else {
        overridable_lookup = _get_lookup_for_target(NULL TSRMLS_CC);
    }
    zend_bool dispatch_stored = FALSE;
    if (overridable_lookup) {
        dispatch_stored = ddtrace_dispatch_store(overridable_lookup, &dispatch);
    }

    if (!dispatch_stored) {
        ddtrace_dispatch_dtor(&dispatch);
    }
    return dispatch_stored;
}
