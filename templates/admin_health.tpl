{strip}
<div class="health">
	{form legend="Health Settings"}
		<input type="hidden" name="page" value="{$page}" />

		<div class="form-group">
			{formlabel label="Tracking folder" for="health_tracking_path"}
			{forminput}
				<input type="text" name="health_tracking_path" id="health_tracking_path" size="50" value="{$healthSettings.health_tracking_path|escape}" />
				{formhelp note="Folder GPS traces from Samsung exercise sessions are archived into, one subfolder per year. It must exist on the machine the import runs on and be writable by the web server. Clear the field to go back to the default, /media3/Maps/_tracking."}
			{/forminput}
		</div>

		<div class="form-group submit">
			<input type="submit" class="btn btn-default" name="save" value="{tr}Apply Settings{/tr}" />
		</div>
	{/form}
</div>
{/strip}
