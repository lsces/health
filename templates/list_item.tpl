{strip}
<div class="display health">
	<div class="header">
		<h1>{$page_title|default:"Health Raw Data"|escape}</h1>
	</div>
	<div class="body">
		<form method="get" action="list_item.php" class="hidden-print">
			<p>
				{foreach $items as $i}
					<label style="margin-right:1em;">
						<input type="radio" name="item" value="{$i.item|escape}"
							{if $i.item eq $selectedItem}checked="checked"{/if}
							onchange="this.form.submit()" />
						{$i.cross_ref_title|escape}
					</label>
				{/foreach}
				<label style="margin-left:1em;">
					<input type="checkbox" name="history" value="y"
						{if $historyRequested}checked="checked"{/if}
						onchange="this.form.submit()" />
					{tr}Show history{/tr}
				</label>
				{if $editRequested}<input type="hidden" name="edit" value="y" />{/if}
			</p>
			<noscript><p><input type="submit" value="{tr}Show{/tr}" /></p></noscript>

			<div class="bitnav bitnav-3col">
				<ul class="pagination">
					<li><a href="{$smarty.const.HEALTH_PKG_URL}index.php"><span class="bitnav-arrow">&laquo;</span> {tr}Back{/tr}</a></li>
				</ul>

				{include file="bitpackage:kernel/bitnav_range_inc.tpl" buttonLabel="Update" bumpDays=31 ownForm=false}

				<ul class="pagination">
					{if $canEditMode}
						{* No nested <form> - this button submits the page's one enclosing form
						   (line 7 above), same "ownForm=false" convention bitnav_range_inc.tpl's
						   own Update button already uses. A <button name=value> only sends that
						   pair when it's the control that triggered the submit, so this alone
						   toggles edit= without needing its own hidden-field set. *}
						<li class="bitnav-gap">
							<button type="submit" name="edit" value="{if $editRequested}{else}y{/if}">{if $editRequested}{tr}Exit Edit{/tr}{else}{tr}Edit{/tr}{/if}</button>
						</li>
					{/if}
					<li class="bitnav-gap"><a href="#" onclick="window.print();return false;">{tr}Print{/tr}</a></li>
				</ul>
			</div>
		</form>

		{if $selectedItem}
			{if $rows}
				<table class="table table-condensed">
					<thead>
						<tr>
							<th>{tr}Day{/tr}</th>
							<th>{tr}Time{/tr}</th>
							<th>{$xkeyTitle|escape}</th>
							<th>{$xkeyExtTitle|escape}</th>
							{if $extraTitle}<th>{$extraTitle|escape}</th>{/if}
							<th>{$dataTitle|escape}</th>
							{if $editMode}<th>{tr}Action{/tr}</th>{/if}
						</tr>
					</thead>
					<tbody>
						{foreach $rows as $r}
						<tr{if $r.is_history} style="background-color:#ffe0e0;"{/if}>
							<td>{$r.day_title|escape}</td>
							<td>{$r.time|escape}</td>
							<td>{$r.xkey|escape}</td>
							<td>{$r.xkey_ext|escape}</td>
							{if $extraTitle}<td>{$r.extra|escape}</td>{/if}
							<td>
								{if $r.data_summary}
									<details><summary>{$r.data_summary|escape}</summary><pre>{$r.data|escape}</pre></details>
								{else}
									{$r.data|escape}
								{/if}
							</td>
							{if $editMode}
								<td>
									{if !$r.is_history}
										<span class="actionicon">
											<a title="{tr}Archive{/tr}"
												href="list_item.php?farchive=1&amp;xref_id={$r.xref_id}&amp;content_id={$r.content_id}&amp;item={$selectedItem|escape:url}&amp;from={$from|escape:url}&amp;to={$to|escape:url}&amp;edit=y{if $historyRequested}&amp;history=y{/if}"
												onclick="return confirm('{tr}Archive this row?{/tr}')">{biticon ipackage="icons" iname="archive-insert" iexplain="Archive"}</a>
										</span>
									{/if}
								</td>
							{/if}
						</tr>
						{/foreach}
					</tbody>
				</table>
				<div class="hidden-print">{pagination}</div>
			{else}
				<p>{tr}No rows for this item.{/tr}</p>
			{/if}
		{/if}
	</div>
</div>
{/strip}
