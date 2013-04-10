<?php
namespace Habari;
if ( !defined( 'HABARI_PATH' ) ) { die('No direct access'); }
?>
<?php include('header.php');?>

<div class="container navigation">
	<div class="pct40">

		<form>
		<select class="navigationdropdown" onChange="navigationDropdown.changePage(this.form.navigationdropdown)" name="navigationdropdown">
			<option value=""><?php _e('Complete User List'); ?></option>
		</select>
		</form>
	</div>
	<span class="or pct20">
		<?php _e('or'); ?>
	</span>
	<span class="pct40">
		<input id="search" type="search" placeholder="<?php _e('search users'); ?>" autofocus="autofocus">
	</span>
</div>

<form method="post" action="" autocomplete="off">
<div class="container users">
	
	<div class="addnewuser item">

		<?php echo $add_user_form; ?>

	</div>

	<?php $theme->display('users_items'); ?>
</div>

<div class="container transparent">
	<div class="controls item">

		<?php echo $delete_users_form; ?>

	</div>
</div>
</form>






<!--<script type="text/javascript">

itemManage.updateURL = habari.url.ajaxUpdateUsers;
itemManage.fetchURL = "<?php /*echo URL::get('admin_ajax', array('context' => 'users')) */?>";
itemManage.fetchReplace = $('.manage.users');

</script>-->

<?php include('footer.php');?>
