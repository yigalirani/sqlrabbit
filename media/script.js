$(document).ready(function() {
   
    $('#data tr:even').addClass('odd');
   $('#data tr').mouseover(function() {
        $(this).addClass('zebraHover');
    });
    $('#data tr').mouseout(function() {
        $(this).removeClass('zebraHover');
    });
});
