{strip}
{if !empty($packageMenuTitle)}<a class="dropdown-toggle" data-toggle="dropdown" href="#"> {tr}{$packageMenuTitle}{/tr} <b class="caret"></b></a>{/if}
<ul class="{$packageMenuClass}">
	<li><a class="item" href="{$smarty.const.HEALTH_PKG_URL}index.php">{tr}Health{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.HEALTH_PKG_URL}list_item.php">{tr}Raw Data{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.HEALTH_PKG_URL}report_range.php">{tr}Report{/tr}</a></li>
</ul>
{/strip}
