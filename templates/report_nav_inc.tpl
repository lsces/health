{* Shared nav/toolbar bar for every Health report page - Back / From-To-Update / Print.
   Include once the calling page has assigned $from/$to. The From-To-Update middle group
   itself lives in bitnav_range_inc.tpl (shared with list_item.tpl's own bar) - see that
   file's docblock. drop this include into any new report_*.php's own template as-is. The
   ?print=1 auto-print script stays in each report's own template, not here - that's
   page-behaviour, not bar markup. *}
<div class="bitnav bitnav-3col hidden-print">
	<ul class="pagination">
		<li><a href="{$smarty.const.HEALTH_PKG_URL}index.php"><span class="bitnav-arrow">&laquo;</span> {tr}Back{/tr}</a></li>
	</ul>

	{include file="bitpackage:health/bitnav_range_inc.tpl" buttonLabel="Update" bumpDays=6}

	<ul class="pagination">
		<li><a href="#" onclick="window.print();return false;">{tr}Print{/tr}</a></li>
	</ul>
</div>
