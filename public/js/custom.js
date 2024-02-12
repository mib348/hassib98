// //allow data-id attribute in popover
// var myDefaultWhiteList = $.fn.tooltip.Constructor.Default.whiteList
// myDefaultWhiteList.a = ['data-id', 'href', 'target']

// $('body').on('click', function (e) {
//     $('[data-toggle=popover]').each(function () {
//         // hide any open popovers when the anywhere else in the body is clicked
//         if (!$(this).is(e.target) && $(this).has(e.target).length === 0 && $('.popover').has(e.target).length === 0) {
//             $(this).popover('hide');
//         }
//     });
// });

$.xhrPool = [];
$.xhrPool.abortAll = function () {
    $(this).each(function (i, jqXHR) {
        jqXHR.abort();
    });
    $.xhrPool.length = 0;
};
$.ajaxSetup({
	beforeSend: function(jqXHR) { $.xhrPool.push(jqXHR); },
	cache:false,
    headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
    },
	error: function (request, status, error) {
		var strErrors = request.responseJSON.message + "<br>";
		var errors = request.responseJSON.errors;
	    $.each(errors, function(index, value){
		   strErrors += value[0] + "<br>";
	    });

        console.log(strErrors);

        // Codebase.helpers('notify', {
        //     align: 'right',             // 'right', 'left', 'center'
        //     from: 'top',                // 'top', 'bottom'
        //     type: 'danger',               // 'info', 'success', 'warning', 'danger'
        //     icon: 'fa fa-danger mr-5',    // Icon class
        //     message: strErrors
        // });
    },
});

$( document ).ajaxSend(function( event, request, settings ) {
	$(".spinning_status").show();
});

$( document ).ajaxStart(function() {
	$(".spinning_status").show();
});

$( document ).ajaxComplete(function() {
	$(".spinning_status").hide();
});

$( document ).ajaxStop(function( event, request, settings ) {
	$(".spinning_status").hide();
});

window.proURIDecoder = function (val)
{
	val=val.replace(/\+/g, '%20');
	var str=val.split("%");
	var cval=str[0];
	for (var i=1;i<str.length;i++)
	{
		cval+=String.fromCharCode(parseInt(str[i].substring(0,2),16))+str[i].substring(2);
	}

	return cval;
}


window.fillDropDown = function (url, dropdown, bUrlEncoded, nSelectedId)
{
	bUrlEncoded = typeof bUrlEncoded !== 'undefined' ? bUrlEncoded : false;
	nSelectedId = typeof nSelectedId !== 'undefined' ? nSelectedId : false;

	$.ajax({
		url: url,
		dataType: "json",
		async:false,
	}).done(function (data) {
		// Clear drop down list
		$(dropdown).find("option").empty();
		// Fill drop down list with new data
		$(data).each(function () {
			// Create option
			var $option = $("<option />");
			// Add value and text to option
			if(bUrlEncoded)
				$option.attr("value", this.value).text(proURIDecoder(this.text));
			else
				$option.attr("value", this.value).text(this.text);

			// Add option to drop down list
			if(nSelectedId == this.value) $option.attr("selected", true);
			$(dropdown).append($option);
		});
	});
}

window.fillDropDownOptgroup = function (url, dropdown, bUrlEncoded, nSelectedId)
{
	bUrlEncoded = typeof bUrlEncoded !== 'undefined' ? bUrlEncoded : false;
	nSelectedId = typeof nSelectedId !== 'undefined' ? nSelectedId : false;

	$.ajax({
		url: url,
		dataType: "json"
	}).done(function (data) {

		$(dropdown).append("<option selected value=>--- Select ---</option>");

		$(data).each(function () {

			//insert optgroup tag
			if (this.label != undefined){
				var $optgroup = $("<optgroup />");

				if(bUrlEncoded)
					$optgroup.attr("label", proURIDecoder(this.label)).text(this.text);
				else
					$optgroup.attr("label", this.label).text(this.text);

				$optgroup.attr("id", "opt"+this.id)
			}

			//insert option tag in specific optgroup with id
			if (this.value != undefined){
				var $option = $("<option />");

				if(bUrlEncoded)
					$option.attr("value", this.value).text(proURIDecoder(this.text));
				else
					$option.attr("value", this.value).text(this.text);

				if(nSelectedId == this.value) $option.attr("selected", true);
				$("#opt"+this.parent_id).append($option);
			}

			$(dropdown).append($optgroup);
		});
	});
}
