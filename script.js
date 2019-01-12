function rrdDropDownSelected(graphId, element)
{
	rangeId = jQuery(element).val();

	rrdSwitchRange(graphId, rangeId);
}

function rrdDoSwitchRange(graphId, rangeId)
{
	var link = jQuery("a#__L" + graphId);
	var image = jQuery("img#__I" + graphId);
	var loader = jQuery("div#__LD" + graphId);
	var allTabs = jQuery("ul#__T" + graphId + " li");
	var currentTab = jQuery("li#__TI" + graphId + "X" + rangeId);
	var select = jQuery("select#__T" + graphId);

	image.addClass("rrdLoading");
	loader.addClass("rrdLoaderActive");
	image.load(function() {
		jQuery(this).removeClass("rrdLoading");
		loader.removeClass("rrdLoaderActive");
	});
	
	var removeQueryRegex = new RegExp("\\?.*$");
	
	var imageUri = image.attr("src");
	imageUri = imageUri.replace(removeQueryRegex, '') + "?range=" + rangeId;
	
	image.attr("src", imageUri);
	link.attr("href", imageUri + "&mode=fs");
	allTabs.removeClass("rrdActiveTab");
	currentTab.addClass("rrdActiveTab");
	select.val(rangeId);
}

function rrdSwitchRange(graphId, rangeId)
{
	var gangedGraphs = jQuery("input:checked[name='rrdgraph_gang']");
	
	if (gangedGraphs.filter("[value='"  + graphId + "']").length > 0)
	{
		gangedGraphs.each(function() {
			rrdDoSwitchRange(jQuery(this).attr("value"), rangeId);
		});		
	}
	else
	{
		rrdDoSwitchRange(graphId, rangeId);
	}
}

function rrdSwitchRangeRelative(jRrdContainer, offset)
{
	var graphId = jRrdContainer.attr("data-graphid");
	var ranges = jRrdContainer.attr("data-ranges");
	var range = jRrdContainer.find("li.rrdActiveTab").index();
	
	range += offset;
	
	if ((range >= 0) && (range < ranges)) rrdSwitchRange(graphId, range);	
}

jQuery().ready(function() {
	var rrdImages = jQuery("div.rrdImage img");
	var downX = 0;
	var downY = 0;
	var clickAllowed = true;
	
	rrdImages.bind("touchstart", function(e) {
		if (e.originalEvent.changedTouches.length > 1) return true;
	
		var me = jQuery(this);
		downX = e.originalEvent.changedTouches[0].pageX;
		downY = e.originalEvent.changedTouches[0].pageY;
		
		clickAllowed = true;
		
		return true;
	});
	
	rrdImages.bind("touchend", function(e) {
		if (e.originalEvent.changedTouches.length > 1) return true;
		
		var me = jQuery(this);
		var upX = e.originalEvent.changedTouches[0].pageX;
		var upY = e.originalEvent.changedTouches[0].pageY;
		
		if ((Math.abs(downX - upX) > 100) && (Math.abs(downY - upY) < 50))
		{
			var event = jQuery.Event((upX > downX) ? "swiperight" : "swipeleft");
			me.trigger(event);
			clickAllowed = false;

			return false;
		}
		else
		{
			return true;
		}
	});
	
	rrdImages.bind("click", function(e) {
		if (!clickAllowed)
		{
			e.preventDefault();
			return false;
		}
	});
	
	rrdImages.bind("swiperight", function() {
		rrdSwitchRangeRelative(jQuery(this).closest("div.rrdImage"), -1);

	});
	
	rrdImages.bind("swipeleft", function() {
		rrdSwitchRangeRelative(jQuery(this).closest("div.rrdImage"), +1);
	});
});
