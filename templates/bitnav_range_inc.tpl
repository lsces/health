{* Self-contained "From/To range + action button" .bitnav group, shared by list_item.tpl and
   report_nav_inc.tpl (in turn used by both report pages) - short-term de-dup ahead of a possible
   proper {range_filter} smarty plugin (see THOUGHTS.txt cross-package item 2), not that plugin
   itself. Only the middle group's markup lives here, not the whole bar - the Back link/Print
   button either side differ per caller and stay in each caller's own template.

   $from/$to are inherited from the calling template's own assigned scope (same convention
   {pagination} already relies on for $listInfo) - not passed as explicit params.

   Params:
   - buttonLabel: submit button text (default 'Update').
   - bumpDays: how many days the auto-bump JS adds to From to fill To (default 6, report pages'
     own week-span default; list_item.tpl passes 31 for its wider raw-data browse window).
   - ownForm: true (default) wraps its own self-submitting <form method="get"
     action=PHP_SELF> (report pages have no other form on the page). Pass false when the
     caller already has an enclosing <form> of its own (list_item.tpl's item-selector form) -
     the button then just submits that outer form directly, no nested <form>.

   One instance per page only - the form/input ids are fixed, not unique-ified. *}
<ul class="pagination">
	<li class="bitnav-picker">
		{if $ownForm|default:true}<form method="get" action="{$smarty.server.PHP_SELF}" id="bitnavRangeForm">{/if}
			<label for="from">{tr}From{/tr}</label>
			<input type="date" name="from" id="from" value="{$from|escape}" onchange="healthBumpTo(this.value, {$bumpDays|default:6})" />
			<label for="to">{tr}To{/tr}</label>
			<input type="date" name="to" id="to" value="{$to|escape}" />
		{if $ownForm|default:true}</form>{/if}
	</li>
	<li class="bitnav-gap"><button type="submit"{if $ownForm|default:true} form="bitnavRangeForm"{/if}>{tr}{$buttonLabel|default:'Update'}{/tr}</button></li>
</ul>
<script>
if( typeof healthBumpTo !== 'function' ) {
	// Changing From bumps To that many days later - always overwrites To, on the assumption
	// that picking a new From means "start a new window from here", not "keep whatever To
	// already had".
	function healthBumpTo( pFromVal, pDays ) {
		if ( !pFromVal ) return;
		var d = new Date( pFromVal + 'T00:00:00' );
		d.setDate( d.getDate() + pDays );
		document.getElementById( 'to' ).value = d.toISOString().slice( 0, 10 );
	}
}
</script>
