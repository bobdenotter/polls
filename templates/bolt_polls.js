
$( document ).ready(function() {

    $('.bolt_poll button').bind('click.boltpoll', function(){
        var poll = $(this).closest('.bolt_poll').attr('id');
        $.ajax({
            url: '{{ paths.root }}' + 'poll_extension/vote',
            type: 'get',
            data: {'vote': $(this).attr('value') },
            success: function (data) {
                console.log(data);
                $('#'+poll).replaceWith(data);
            }
        });
    });
});
