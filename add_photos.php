<?php
/*
 * Purpose: Provide `upload` function, replace the function in the plugin
 *          `community`. Advanced ACL support.
 * Author : Piwigo, plg, icy
 * License: GPL2
 * Note   :
 *  This source is based on `add_photos.php` from the plugin `community`.
 *  This script requires `curl_exec` to be supported (see the definition
 *  of `fetchRemote` in `admin/include/functions.php`) If this method is
 *  in black-list (by, e.g, suhosin) all images will be uploaded but they
 *  are put in `pending` mode (that means that the option `moderate_image`
 *  is always `true`. We may consider to add some option to notify the
 *  user about this. FIXME: How to know if a method is in black-list?)
 */

if (!defined('ICY_PICTURE_MODIFY_PATH')) die('Hacking attempt!');

global $template, $conf, $user;

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

if (!icy_plugin_enabled("community")) {
  die('Something wrong happended. The plugin "community" must be enabled to use this function.');
}

include_once(COMMUNITY_PATH.'include/functions_community.inc.php');

if (!defined('PHOTOS_ADD_BASE_URL')) {
  define('PHOTOS_ADD_BASE_URL', make_index_url(array('section' => 'add_photos')));
}

icy_acl_fix_community(icy_acl_load_configuration());

$user_permissions = community_get_user_permissions($user['id']);

if (count($user_permissions['upload_categories']) == 0 and !$user_permissions ['create_whole_gallery'])
{
  redirect(make_index_url());
}

// +-----------------------------------------------------------------------+
// |                             process form                              |
// +-----------------------------------------------------------------------+

$page['errors'] = array();
$page['infos'] = array();

// this is for "browser uploader", for Flash Uploader the problem is solved
// with function community_uploadify_privacy_level (see main.inc.php)
$_POST['level'] = 16;

if (isset($_GET['processed']))
{
  $hacking_attempt = false;

  // is the user authorized to upload in this album?
  if (!in_array($_POST['category'], $user_permissions['upload_categories']))
  {
    echo 'Hacking attempt, you have no permission to upload in this album';
    $hacking_attempt = true;
  }

  if ($hacking_attempt)
  {
    if (isset($_SESSION['uploads'][ $_POST['upload_id'] ]))
    {
      delete_elements($_SESSION['uploads'][ $_POST['upload_id'] ], true);
      icy_action_log("upload_image", '0', "Fail");
    }
    exit();
  }
}

include_once(PHPWG_ROOT_PATH.'admin/include/photos_add_direct_process.inc.php');

if (isset($image_ids) and count($image_ids) > 0)
{
  // reinitialize the informations to display on the result page
  $page['infos'] = array();

  if (isset($_POST['set_photo_properties']))
  {
    $data = array();

    $data['name'] = $_POST['name'];
    $data['author'] = $_POST['author'];

    if ($conf['allow_html_descriptions'])
    {
      $data['comment'] = @$_POST['description'];
    }
    else
    {
      $data['comment'] = strip_tags(@$_POST['description']);
    }

    $updates = array();
    foreach ($image_ids as $image_id)
    {
      $update = $data;
      $update['id'] = $image_id;
      array_push($updates, $update);
    }

    mass_updates(
      IMAGES_TABLE,
      array(
        'primary' => array('id'),
        'update' => array_diff(array_keys($updates[0]), array('id'))
        ),
      $updates
      );
  }

  // $category_id is set in the photos_add_direct_process.inc.php included script
  $category_infos = get_cat_info($category_id);
  $category_name = get_cat_display_name($category_infos['upper_names']);

  array_push(
    $page['infos'],
    sprintf(
      l10n('%d photos uploaded into album "%s"'),
      count($page['thumbnails']),
      '<em>'.$category_name.'</em>'
      )
    );

  // should the photos be moderated?
  //
  // if one of the user community permissions is not moderated on the path
  // to gallery root, then the upload is not moderated. For example, if the
  // user is allowed to upload to events/parties with no admin moderation,
  // then he's not moderated when uploading in
  // events/parties/happyNewYear2011
  $moderate = (icy_acl("moderate_image") and icy_plugin_enabled("community"));
  if ($moderate)
  {
    $inserts = array();

    $query = '
SELECT
    id,
    date_available
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $image_ids).')
;';
    $result = pwg_query($query);
    while ($row = pwg_db_fetch_assoc($result))
    {
      array_push(
        $inserts,
        array(
          'image_id' => $row['id'],
          'added_on' => $row['date_available'],
          'state' => 'moderation_pending',
          )
        );
      icy_action_log("upload_image.pending", $image_id, 'Success', $category_id);
    }

    mass_inserts(
      COMMUNITY_PENDINGS_TABLE,
      array_keys($inserts[0]),
      $inserts
      );

    if (isset($conf['prefix_thumbnail'])) {
      // the link on thumbnail must go to the websize photo
      foreach ($page['thumbnails'] as $idx => $thumbnail)
      {
        $page['thumbnails'][$idx]['link'] = str_replace(
          'thumbnail/'.$conf['prefix_thumbnail'],
          '',
          $thumbnail['src']
          );
      }
    }

    array_push(
      $page['infos'],
      l10n('Your photos are waiting for validation, administrators have been notified')
      );
  }
  else
  {

    // the level of a user upload photo with no moderation is 0
    $query = '
UPDATE '.IMAGES_TABLE.'
  SET level = 0
  WHERE id IN ('.implode(',', $image_ids).')
;';
    pwg_query($query);

    // the link on thumbnail must go to picture.php
    foreach ($page['thumbnails'] as $idx => $thumbnail)
    {
      if (preg_match('/(image_id=|photo-)(\d+)/', $thumbnail['link'], $matches))
      {
        $page['thumbnails'][$idx]['link'] = make_picture_url(
          array(
            'image_id' => $matches[2],
            'image_file' => $thumbnail['file'],
            'category' => $category_infos,
            )
          );
        icy_action_log("upload_image", $matches[2], 'Success', $category_id);
      }
    }
  }

  invalidate_user_cache();

  // let's notify administrators
  include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');

  $keyargs_content = array(
    get_l10n_args('Hi administrators,', ''),
    get_l10n_args('', ''),
    get_l10n_args('Album: %s', get_cat_display_name($category_infos['upper_names'], null, false)),
    get_l10n_args('User: %s', $user['username']),
    get_l10n_args('Email: %s', $user['email']),
    );

  if ($moderate)
  {
    $keyargs_content[] = get_l10n_args('', '');

    array_push(
      $keyargs_content,
      get_l10n_args(
        'Validation page: %s',
        get_absolute_root_url().'admin.php?page=plugin-community-pendings'
        )
      );
  }

  pwg_mail_notification_admins(
    get_l10n_args('%d photos uploaded by %s', array(count($image_ids), $user['username'])),
    $keyargs_content,
    false
    );
}

// +-----------------------------------------------------------------------+
// |                             prepare form                              |
// +-----------------------------------------------------------------------+

$template->set_filenames(array('add_photos' =>  dirname(__FILE__).'/../community/add_photos.tpl'));

include_once(PHPWG_ROOT_PATH.'admin/include/photos_add_direct_prepare.inc.php');

// we have to change the list of uploadable albums
$upload_categories = $user_permissions['upload_categories'];
if (count($upload_categories) == 0)
{
  $upload_categories = array(-1);
}

$query = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', $upload_categories).')
;';

display_select_cat_wrapper(
  $query,
  $selected_category,
  'category_options'
  );

$create_subcategories = false;
if ($user_permissions['create_whole_gallery'] or count($user_permissions['create_categories']) > 0)
{
  $create_subcategories = true;
}

$create_categories = $user_permissions['create_categories'];
if (count($user_permissions['create_categories']) == 0)
{
  $create_categories = array(-1);
}

$query = '
SELECT id,name,uppercats,global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', $create_categories).')
;';

display_select_cat_wrapper(
  $query,
  $selected_category,
  'category_parent_options'
  );

$template->assign(
  array(
    'create_subcategories' => $create_subcategories,
    'create_whole_gallery' => $user_permissions['create_whole_gallery'],
    )
  );

if (isset($conf['community_ask_for_properties']) and $conf['community_ask_for_properties'])
{
  $template->assign(
    array(
      'community_ask_for_properties' => true,
      )
    );
}

// +-----------------------------------------------------------------------+
// |                             display page                              |
// +-----------------------------------------------------------------------+

if (count($page['errors']) != 0)
{
  $template->assign('errors', $page['errors']);
}

if (count($page['infos']) != 0)
{
  $template->assign('infos', $page['infos']);
}

$title = l10n('Upload Photos');
$page['body_id'] = 'theUploadPage';

$template->assign_var_from_handle('PLUGIN_INDEX_CONTENT_BEGIN', 'add_photos');

$template->clear_assign(array('U_MODE_POSTED', 'U_MODE_CREATED'));

$template->assign(
  array(
    'TITLE' => '<a href="'.get_gallery_home_url().'">'.l10n('Home').'</a>'.$conf['level_separator'].$title,
    )
  );
?>
