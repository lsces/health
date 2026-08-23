{strip}
<div class="display health">
	<div class="header">
		<h1>{tr}Day{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>
	<div class="body">
		{jstabs}
			{jstab title="Summary"}
				<table class="table table-condensed">
					<tbody>
						{if $wtSummary}
							<tr><td>{tr}Weight{/tr}</td><td>{$wtSummary.weight|string_format:"%.1f"}kg</td></tr>
						{/if}
						{if $bpCount}
							<tr><td>{tr}Blood Pressure{/tr}</td><td>{$bpCount} {if $bpCount eq 1}{tr}reading{/tr}{else}{tr}readings{/tr}{/if}</td></tr>
						{/if}
						{if $hrMin !== null}
							<tr><td>{tr}Pulse Range{/tr}</td><td>{$hrMin}&ndash;{$hrMax} bpm</td></tr>
						{/if}
						{if !$wtSummary && !$bpCount && $hrMin === null}
							<tr><td colspan="2">{tr}Nothing to summarise for this day yet.{/tr}</td></tr>
						{/if}
					</tbody>
				</table>
			{/jstab}

			{jstab title="Data"}
				{if $gXrefInfo && $gXrefInfo->mGroups}
					{jstabs}
						{foreach $gXrefInfo->mGroups as $group}
							{include file=$gContent->getXrefListTemplate($group->mTemplate) xrefGroup=$group allow_edit=false allow_add=false}
						{/foreach}
					{/jstabs}
				{else}
					<p>{tr}Nothing logged for this day.{/tr}</p>
				{/if}
			{/jstab}
		{/jstabs}
	</div>
</div>
{/strip}
