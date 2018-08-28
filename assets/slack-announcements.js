(function (doc, $) {

    $(doc).ready(function () {
        $('.jq-announce').click(function(){
            if( !$('.jq-announce-channels:checked').length ){
                $('.jq-announce-channels-desc').hide().next().show();
                return false;
            } else {
                $('.jq-announce-channels-desc').show().next().hide();
            }
        });
    });

})(document, jQuery);