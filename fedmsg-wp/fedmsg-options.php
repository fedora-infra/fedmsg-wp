<div class="wrap">
<h2>Fedmsg Plugin Configuration</h2>
<form method="post" action="options.php">
<?php

settings_fields( 'fedmsg-option-group' );
do_settings_sections( 'fedmsg-option-group' );

?>
<?php submit_button(); ?>
</form>
</div>
