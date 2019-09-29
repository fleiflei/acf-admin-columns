jQuery(function ($) {


    $(document).ready(function () {

        $('.aac-field-settings-admin_column_enabled').each(function () {

            // hide "sub" fields on load
            $(this).parents('.acf-field-object').find('.acf-field-setting-admin_column_post_types').toggle($(this).prop('checked'));

        });

        // toggle "sub" fields that should only be shown when admin column option is enabled
        $('.aac-field-settings-admin_column_enabled').on('change', function (e) {
            $(this).parents('.acf-field-object').find('.acf-field-setting-admin_column_post_types').toggle($(this).prop('checked'));
        });

    });
});