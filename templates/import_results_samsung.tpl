{strip}
<div class="display health">
	<div class="header">
		<h1>{$page_title|default:"Upload Samsung Health Export"|escape}</h1>
	</div>
	<div class="body">
		<div class="bitnav">
			<ul class="pagination">
				<li><a href="{$smarty.const.HEALTH_PKG_URL}index.php"><span class="bitnav-arrow">&laquo;</span> {tr}Back{/tr}</a></li>
			</ul>

			{if $uploadForm}
				<ul class="pagination">
					<li class="bitnav-picker">
						<form method="post" enctype="multipart/form-data" id="samsungUploadForm">
							<input type="file" name="export_file" accept=".tar.gz,.tgz,.zip,application/gzip,application/zip" />
						</form>
					</li>
					<li class="bitnav-gap"><button type="submit" form="samsungUploadForm">{tr}Upload &amp; Run{/tr}</button></li>
				</ul>
			{/if}
		</div>

		{if $csvFile}
			<p>{tr}File{/tr}: <code>{$csvFile|escape}</code></p>
		{/if}

		{if $years}
			<p>{tr}Years touched{/tr}: {foreach $years as $y}{$y|escape}{if !$y@last}, {/if}{/foreach}</p>
		{/if}

		{if $types}
			<table class="table table-condensed">
				<thead><tr><th>{tr}Type{/tr}</th><th>{tr}Result{/tr}</th></tr></thead>
				<tbody>
					{foreach $types as $label => $r}
					<tr>
						<td>{$label|escape}</td>
						<td>{$r.summary|escape}</td>
					</tr>
					{/foreach}
				</tbody>
			</table>

			{foreach $types as $label => $r}
				{if $r.errors}
					<h3>{$label|escape} {tr}errors{/tr}</h3>
					<ul>
						{foreach $r.errors as $msg}
							<li>{$msg|escape}</li>
						{/foreach}
					</ul>
				{/if}
			{/foreach}
		{/if}

		{if $errors}
			<h3>{tr}Errors{/tr}</h3>
			<ul>
				{foreach $errors as $msg}
					<li>{$msg|escape}</li>
				{/foreach}
			</ul>
		{/if}
	</div>
</div>
{/strip}
