{* Shared nav/toolbar bar for every Health report page - Back / From-To-Update / Print.
   Include once the calling page has assigned $from/$to. Self-submits back to whichever
   report page includes it via $smarty.server.PHP_SELF (same convention as
   contact/templates/list_contacts.tpl etc.), so no per-report action URL needs passing
   in - drop this include into any new report_*.php's own template as-is. The
   healthReportBumpTo() JS helper and (where wanted) the ?print=1 auto-print script stay
   in each report's own template, not here - those are page-behaviour, not bar markup. *}
<div class="bitnav hidden-print">
	<ul class="pagination">
		<li><a href="{$smarty.const.HEALTH_PKG_URL}index.php"><span class="bitnav-arrow">&laquo;</span> {tr}Back{/tr}</a></li>
	</ul>

	<ul class="pagination">
		<li class="bitnav-picker">
			<form method="get" action="{$smarty.server.PHP_SELF}" id="reportNavForm">
				<label for="from">{tr}From{/tr}</label>
				<input type="date" name="from" id="from" value="{$from|escape}" onchange="healthReportBumpTo(this.value)" />
				<label for="to">{tr}To{/tr}</label>
				<input type="date" name="to" id="to" value="{$to|escape}" />
			</form>
		</li>
		<li style="float:left; margin-left:8px"><button type="submit" form="reportNavForm">{tr}Update{/tr}</button></li>
	</ul>

	<ul class="pagination">
		<li><a href="#" onclick="window.print();return false;">{tr}Print{/tr}</a></li>
	</ul>
</div>
