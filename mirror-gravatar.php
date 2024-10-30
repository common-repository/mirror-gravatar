<?php
/*
Plugin Name: Mirror Gravatar
Plugin URI: https://www.jwz.org/mirror-gravatar/
Version: 1.4
Description: Locally mirror commenters' Gravatar, Libravatar or Mastodon avatar images.
Author: Jamie Zawinski
Author URI: https://www.jwz.org/
*/

/* Copyright © 2022-2024 Jamie Zawinski <jwz@jwz.org>

   Permission to use, copy, modify, distribute, and sell this software and its
   documentation for any purpose is hereby granted without fee, provided that
   the above copyright notice appear in all copies and that both that
   copyright notice and this permission notice appear in supporting
   documentation.  No representations are made about the suitability of this
   software for any purpose.  It is provided "as is" without express or 
   implied warranty.

   Created: 31-May-2022.


   Available actions:

     gravatar_downloaded_image -- called when a file has been created.

 */

$mirror_gravatar_plugin_version = "1.4";
$mirror_gravatar_plugin_name    = 'mirror-gravatar';
$mirror_gravatar_mystery_image  = 'mystery128.png';


// Download JSON gravatar info from the URL, or null.
//
function mirror_gravatar_load_url ($url, $desc, $json_p) {

  if ($json_p)
    $response = wp_remote_get ($url);
  else
    $response = wp_remote_head ($url);

  $code = wp_remote_retrieve_response_code ($response);

  if (! preg_match ('/^2\d\d/', $code)) {
    if ($code == '404')
      ; // error_log ("mirror-gravatar: no avatar: $desc");
    else if (!$code)
      error_log ("mirror-gravatar: null response: $desc: $url");
    else
      error_log ("mirror-gravatar: error $code: $desc: $url");
    return null;
  }

  if (! $json_p) {
    // Fake up some JSON.
    $json = [ 'entry' => [[ 'thumbnailUrl' => $url ]]];

  } else {
    $txt = wp_remote_retrieve_body ($response);

    if (!$txt) {
      error_log ("mirror-gravatar: null response: $desc: $url");
      return null;
    }

    $json = json_decode ($txt, true);
    if (!$json) {
      error_log ("mirror-gravatar: json error: $desc: $url - " .
                 json_last_error_msg());
      return null;
    }

    if (!is_array ($json)) {
      // Probably an error string, e.g. "User not found"
      error_log ("mirror-gravatar: error: $desc: $json");
      return null;
    }
  }

  return $json;
}


// Returns the base URL to which Libravatar requests should be sent for this
// email address.
//
function mirror_gravatar_libravatar_base_url ($email) {

  if (preg_match ('/@([^@\s]+)\s*$/', $email, $m)) {
    $dom = strtolower ($m[1]);
    $sub = '_avatars-sec._tcp.';    // dig +short SRV _avatars-sec._tcp.$dom
    $srv = dns_get_record ("$sub$dom", DNS_SRV);
    if ($srv && count ($srv)) {
      // We're supposed to randomize by priority and weight, but screw it.
      $srv = $srv[0];
      if ($srv['target']) {
        $port = $srv['port'] ?? null;
        return ('https://' .
                (($port && $port != 443) ? ":$port" : "") .
                $srv['target']);
      }
    }
  }

  return 'https://seccdn.libravatar.org';
}


// Download and save the gravatar info of this comment's author into the
// comment's metadata.
//
function mirror_gravatar_download_metadata ($id, $commentdata = null) {

  if (! $commentdata)
    $commentdata = get_comment ($id, ARRAY_A);

  if (! $commentdata) return;
  $email = $commentdata['comment_author_email'] ?? null;
  $desc = $email;

  $json = null;

  // First, try looking up the email address on Gravatar.
  //
  // We check this first because Gravatar supports user profile info
  // and libravatar does not.
  //
  if ($email && !$json) {
    $desc = $email;
    $hash = hash ('sha256', strtolower (trim ($email)));
    $url = "https://www.gravatar.com/$hash.json";
    $json = mirror_gravatar_load_url ($url, $desc, true);
  }

  // Next, try looking up the email address on Libravatar.
  // It falls back to gravatar.com, but we already checked there.
  // https://wiki.libravatar.org/api/
  //
  if ($email && !$json) {
    $desc = $email;
    $hash = hash ('sha256', strtolower (trim ($email)));
    $base = mirror_gravatar_libravatar_base_url ($email);
    $url = "$base/avatar/$hash";
    $json = mirror_gravatar_load_url ($url, $desc, false);
    if ($json) {
      // Libravatar doesn't provide profile data, only images,
      // so the JSON we have is faked.  Fake it some more.
      $json['entry'][0]['libravatar'] = true;
      $json['entry'][0]['hash']       = $hash;
      $json['entry'][0]['emails']     = [[ 'value' => $email ]];
      // error_log ("mirror-gravatar: found libravatar: $id $desc");
    }
  }

  if ($json) {
    // Gravatar data is inside { entry: [ profile1, profile2, ... ] }
    $json = $json['entry'] ?? null;
    if (!$json) {
      error_log ("mirror-gravatar: error: no entry in G json: $id $desc");
      $json = null;
    }
  }

  // Next, see if the URL looks like a Mastodon URL.
  //
  if (! $json) {
    $url = $commentdata['comment_author_url'] ?? '';
    if ($url &&
        preg_match ('!^https?://([^/:@]+)/@([^/:@]+)/?$!i',
                    // "https://instance/@user"
                    $url, $m) ||
        preg_match ('!^https?://([^/:@]+)/(?:users|profile)/([^/:@]+)/?$!i',
                    // "https://pleroma.../users/user"
                    $url, $m)) {
      $url  = 'https://' . $m[1] . '/api/v1/accounts/lookup?acct=' . $m[2];
      $desc = '@' . $m[2] . '@' . $m[1];
      $json = mirror_gravatar_load_url ($url, $desc, true);
      if ($json) {
        if ($json['username'] ?? null) {
          // Tag the saved data as being Mastodon instead of Gravatar.
          $json['mastodon'] = $desc;
          // Convert single profile to a list of profiles, to match Gravatar.
          $json = [ $json ];
        } else {
          error_log ("mirror-gravatar: error: no entry in M json: $id $desc");
          $json = null;
        }
      }
    }
  }

  if ($json) {
    update_comment_meta ($id, 'gravatar', $json);
    mirror_gravatar_download_image ($id, $json);
  } else if ($desc) {
    error_log ("mirror-gravatar: no avatar: $desc");
  }
}


// Returns the directory containing this plugin.
//
function mirror_gravatar_plugin_dir() {
  return apply_filters ('mirror_gravatar_plugin_dir',
                        plugin_dir_path (__FILE__));
}


// Download the comment's avatar image file to a local cache.
// Replace the existing image if there is one.
//
function mirror_gravatar_download_image ($id, $json) {

  $ext = null;

  $g = $json;
  if ($g && ($g[0] ?? null))	// Either a list of profiles, or just one
    $g = $g[0];

  if ($g['mastodon'] ?? false) {

    // Store file for "@user@host" as "XX/user.host.png"

    $url = $g['avatar'];
    if (!$url) {
      error_log ("mirror-gravatar: download: no mastodon avatar in $id");
      return;
    }

    if (preg_match ('@\.([^/.]+)$@', $url, $m))
      $ext = strtolower ($m[1]);

    $file = strtolower ($g['mastodon']);
    $file = preg_replace ('/@/', '.', $file);

  } else {				// Gravatar or Libravatar

    // Screens are big now. Always download a large-ish avatar image,
    // and let the browser scale it down.
    $avatar_size = 128;

    $url  = $g['thumbnailUrl'] ?? null;
    $hash = $g['hash'] ?? null;

    if (!$url) {
      error_log ("mirror-gravatar: download: no thumb for $id");
      return;
    }

    if (!$hash) {
      error_log ("mirror-gravatar: download: no hash for $id");
      return;
    }

    $ext = 'png';
    $url .= (".$ext" .
             '?s=' . $avatar_size .
             '&r=x' .    // Rating
             '&d=404');  // 404 instead of default "G" image

    // Use the hash in the file name so that image URLs can't be reversed
    // back into email addresses.
    $file = $hash;
  }

  $file = preg_replace ('/^[-_.]/', '', $file);		  // Leading dot
  $file = preg_replace ('/[^-_.A-Za-z\d]/s', '', $file);  // Sanitize
  if (strlen($file) < 10) {
    error_log ("mirror-gravatar: download: bogus file: $id $file");
    return;
  }

  if ($g['mastodon'] ?? false)
    $subdir = sprintf ('%02x', ord ($file[0])); // Char 0 as hex
  else
    $subdir = substr ($file, 0, 2);  // "../AB/ABCDEF..."

  $base = mirror_gravatar_plugin_dir();
  $path = $base . 'gravatar/' . $subdir;

  if (! is_dir ($path)) {
    if (! @mkdir ($path, 0777, true)) {
      error_log ("mirror-gravatar: mkdir failed: $path");
      return;
    }
  }

  // I wish the Gravatar files had correct extensions, but that would be
  // expensive.  We could get the Content-Type from the response headers, but
  // then we'd have to probe the file system multiple times to see which type
  // of file existed for this hash.  But it turns out that no matter what
  // extension you tack onto the end of the image request, Gravatar returns
  // PNG data (even if you specified .jpg).  So that's fine.

  // Ugh, we always have to use the same extension, even if Mastodon says
  // it's a GIF -- otherwise mirror_gravatar_pre_get_avatar() has to glob
  // the directory or probe every possible file extension to figure out
  // which one is there.  So file GIFs as PNGs and hope that always works.
  $ext = null;

  if (! $ext) $ext = 'png';
  if ($ext == 'jpeg') $ext = 'jpg';
  $file .= '.' . $ext;
  $path .= '/' . $file;

  // Download the file again even if it already exists, so that the user
  // has the opportunity to retroactively change their profile image each
  // time they post.
  //
  $response = wp_remote_get ($url);
  $status = wp_remote_retrieve_response_code ($response);
  if (! $status) $status = 500;

  $data = wp_remote_retrieve_body ($response);

  // We could write to the file directly, but then it would blow away the old
  // file if there was a network error, so download to RAM and then save.
  //
  if ($status < 200 || $status >= 300) {	// 200, one hopes
    error_log ("mirror-gravatar: download: status: $status: $id");
    return;
  }
  if (!$data) {
    error_log ("mirror-gravatar: download: null response: $id");
    return;
  }
  if (preg_match('/^</s', $data)) {
    error_log ("mirror-gravatar: download: HTML response: $id");
    return;
  }
  if (preg_match('/^404/s', $data)) {    // They return '404 Not Found'
    error_log ("mirror-gravatar: download: fake 404: $id");
    return;
  }

  if (! @file_put_contents ($path, $data, LOCK_EX)) {
    error_log ("mirror-gravatar: download: error writing: $path");
    return;
  }

  // error_log ("mirror-gravatar: downloaded: $id: $path");
  do_action ('gravatar_downloaded_image', $path, $id, $json);
}


// If we have a mirrored gravatar image, use that.
//
add_filter ('pre_get_avatar', 'mirror_gravatar_pre_get_avatar', 10, 3);
function mirror_gravatar_pre_get_avatar ($html, $id, $args) {
  global $mirror_gravatar_mystery_image;

  if (! ($id instanceof WP_Comment)) return $html;

  // To determine the saved avatar, look at the metadata that was stored
  // on this comment by mirror_gravatar_download_metadata() at the time
  // that the comment was posted.
  //
  $g = get_comment_meta ($id->comment_ID, 'gravatar', true);
  if (! $g) return $html;

  if ($g && ($g[0] ?? null))  // Either a list of profiles, or just one
    $g = $g[0];

  $base_dir = mirror_gravatar_plugin_dir();
  $base_url = plugin_dir_url (__FILE__);

  $file     = null;
  $class    = 'avatar';
  $hash     = $g['hash']     ?? null;
  $mastodon = $g['mastodon'] ?? null;

  // See if this comment has a saved hashed image file.
  // The hash was MD5 for comments made prior to v1.3 (2024), SHA256 after.
  //
  if (!$file && $hash) {
    $subdir = substr ($hash, 0, 2);  // "../AB/ABCDEF..."
    $file = 'gravatar/' . $subdir . '/' . $hash . '.png';
    $path = $base_dir . $file;
    if (! file_exists ($path))
      $file = null;

    if ($file && ($g['libravatar'] ?? false))
      $class .= ' libravatar';
  }

  // See if this URL has a saved Mastodon image file.
  //
  if (!$file && $mastodon &&
      preg_match ('/^@?(.*)@(.*?)$/', $mastodon, $m)) {
    $file = strtolower ($m[1] . '.' . $m[2]);
    $file = preg_replace ('/^[-_.]/', '', $file);	    // Leading dot
    $file = preg_replace ('/[^-_.A-Za-z\d]/s', '', $file);  // Sanitize
    $subdir = sprintf ('%02x', ord($file[0]));              // Char 0 as hex

    // Always assume file ends in PNG, see mirror_gravatar_download_image().
    $file = 'gravatar/' . $subdir . '/' . $file . '.png';
    $path = $base_dir . $file;
    if (!file_exists ($path))
      $file = null;

    if ($file)
      $class .= ' mastodon';
  }

  if ($file) {
    $url = $base_url . $file;
  } else {
    $url = get_option ('avatar_default', 'mystery');

    if (preg_match ('/^https?:/', $url)) {	// Custom mystery image URL.
    } else if ($url == 'blank') {		// Emit a blank space-filler.
      $url = null;
    } else if ($url == 'mystery' ||		// Use our Gravatar lookalike.
               $url == 'gravatar_default') {
      $url = $base_url . $mirror_gravatar_mystery_image;
    } else {
      // If it is set to 'identicon', 'wavatar', 'monsterid', 'retro', or
      // any other Gravatar-server-generated image computed from the email
      // hash, we can't do anything with that, so just return it as-is.
      // I guess we could instead download and cache those generated images?
      error_log ("mirror-gravatar: avatar_default = \"$url\"");
      return $html;
    }
  }

  $html = sprintf (($url
                    ? "<img src='%s' alt='%s' class='$class'" .
                      " width='%d' height='%d' %s />"
                    : "<div alt='%s' class='$class'" .   // null non-img
                      " width='%d' height='%d' %s" .
                      " style='display: inline-block'></div>"),
                   esc_url ($url),
                   esc_attr ($args['alt']),
                   (int) $args['width'],
                   (int) $args['height'],
                   htmlspecialchars ($args['extra_attr']));

  return $html;
}


// This does three things:
//
// - Downloads the comment's Gravatar or Mastodon profile info to comment
//   metadata (visible on the Edit Comment page);
// - Saves the Gravatar image file to a local cache;
// - If the comment doe not already have a URL set, sets it to the Gravatar
//   profile page.
//
// Must have lower priority than wp_new_comment_notify_postauthor.
//
add_action ('comment_post', 'mirror_gravatar_comment_url_fallback', 2, 3);
function mirror_gravatar_comment_url_fallback ($id, $approved, $commentdata) {

  // Save metadata and download image file.
  mirror_gravatar_download_metadata ($id, $commentdata);

  // If there is no URL, but there is a Gravatar profile, use that URL.
  if (! ($commentdata['comment_author_url'] ?? null)) {
    $g = get_comment_meta ($id, 'gravatar', true);
    if ($g && ($g[0] ?? null))  // Either a list of profiles, or just one
      $g = $g[0];
    if (!$g) return;
    $url = $g['profileUrl'] ?? null;
    if (! $url) $url = $g['url'] ?? null;
    if (!$url) return;
    $url = preg_replace ('/^http:/si', 'https:', $url);

    // Update the DB with the new comment author URL.
    // The $commentdata argument is incomplete: no comment_ID, among others.
    $commentdata = get_comment ($id, ARRAY_A);
    $commentdata['comment_author_url'] = $url;
    wp_update_comment ($commentdata);
  }
}


/*************************************************************************
 Gravatar live preview when posting
 *************************************************************************/


// Put a DIV around the comment fields so we can size them sensibly.
// Also put a Gravatar after (to the right of) the fields.
// It is updated live by JS as the user enters their email address.
//
add_filter ('comment_form_fields', 'mirror_gravatar_wrap_comment_headers');
function mirror_gravatar_wrap_comment_headers ($fields) {

  global $mirror_gravatar_plugin_version;
  global $mirror_gravatar_plugin_name;
  global $mirror_gravatar_mystery_image;

  // Find the fields that are not the comment entry, to wrap them in a DIV.
  $first = null;
  $last  = null;
  foreach ($fields as $k => $v) {
    if ($k != 'comment') {
      if (!$first) $first = $k;
      $last = $k;
    }
  }

  if (! $first)
    return $fields;

  // Load the JavaScript for the live gravatar preview.
  //
  $base_url = plugin_dir_url (__FILE__);
  $n = $mirror_gravatar_plugin_name;
  $v = $mirror_gravatar_plugin_version;
  wp_enqueue_script ('cryptoJS-sha256', $base_url ."sha256.js", [], '3.1.2');
  wp_enqueue_script ($n, $base_url . $n . '.js',  [], $v);
  wp_enqueue_style  ($n, $base_url . $n . '.css', [], $v);

  // Wrap the fields, and emit the HTML for the live Gravatar preview.
  //
  $url = get_option ('avatar_default', '');
  if (! ($url && preg_match ('/^https?:/', $url)))
    $url = $base_url . $mirror_gravatar_mystery_image;

  $g = ('<DIV CLASS="comment-form-gravatar">' .
         '<IMG SRC="' . esc_url($url) . '"' .
             ' CLASS="avatar" ALT="" />' .
         '<SPAN ID="comment_reply_gravatar_blurb">' .
          '<A HREF="https://www.gravatar.com/" TARGET="_blank">' .
           htmlspecialchars (__('Set your avatar')) .
          '</A>' .
         '</SPAN>' .
        '</DIV>');

  // Allow filters to move the images to CDN.
  $g = apply_filters ('pre_get_avatar', $g, null, null);

  $header = ('<DIV CLASS="comment_fields_box">' .
             '<DIV CLASS="comment_fields">');
  $footer = '</DIV>' . $g . '</DIV>';

  $fields[$first] = $header . $fields[$first];
  $fields[$last]  = $fields[$last] . $footer;

  return $fields;
}


/*************************************************************************
 Admin pages
 *************************************************************************/

// Print out the Gravatar user info that we saved into comment metadata
// on the 'Edit Comment' page.
//
add_action ('add_meta_boxes_comment', 'mirror_gravatar_meta_box', 11);
function mirror_gravatar_meta_box() {
  global $mirror_gravatar_plugin_name;
  add_meta_box ($mirror_gravatar_plugin_name, __('Gravatar'),
                'mirror_gravatar_meta_box_cb',
                'comment', 'normal', 'high');
}


function mirror_gravatar_meta_box_cb ($comment) {
  $g = get_comment_meta ($comment->comment_ID, 'gravatar', true);
  if (!$g) {
    print "None";
    return;
  }

  if ($g && ($g[0] ?? null))  // Either a list of profiles, or just one
    $g = $g[0];

  if (!$g) return;

  if ($g['photos'] ?? null) {
    foreach ($g['photos'] as $a) {
      print ('<IMG SRC="' . esc_url ($a['value']) .
             '" STYLE="width: 96px; height: auto; border: 1px solid;'.
             ' margin: 4px; float: right;">');
    }
  } else if ($g['thumbnailUrl'] ?? null) {
    // Libravatar
    print ('<IMG SRC="' . esc_url ($g['thumbnailUrl']) .
           '" STYLE="width: 96px; height: auto; border: 1px solid;'.
           ' margin: 4px; float: right;">');
  }

  if (($g['mastodon'] ?? null) && ($g['avatar'] ?? null)) {
    print ('<IMG SRC="' . esc_url ($g['avatar']) .
           '" STYLE="width: 96px; height: auto; border: 1px solid;'.
           ' margin: 4px; float: right;">');
  }

  if ($g['profileUrl'] ?? null) {
    print '<A HREF="' . esc_url ($g['profileUrl']) . '">';
    if ($g['name'] ?? null) {
      $n1 = (($g['name']['givenName'] ?? '') . ' ' . 
             ($g['name']['familyName'] ?? ''));
      $n2 = ($g['name']['formatted'] ?? '');
      print htmlspecialchars($n1);
      if ($n2 != $n1) print ' (' . htmlspecialchars($n2) . ')';
    } else {
      print htmlspecialchars($g['preferredUsername'] ?? 'unknown');
    }
    print "</A><BR>";
  }

  if ($g['mastodon'] ?? null) {
    print '<A HREF="' . esc_url ($g['url']) . '">';
    $n = trim ($g['display_name'] ?? '');
    if (!$n) $n = trim ($g['username'] ?? '');
    if (!$n) $n = trim ($g['mastodon'] ?? '');
    print htmlspecialchars($n);
    print '</A> - ';
    print htmlspecialchars($g['mastodon']);
    print '<BR>';
  }

  if ($g['currentLocation'] ?? null)
    print htmlspecialchars($g['currentLocation']) . "<BR>";

  if ($g['emails'] ?? null) {
    $i = 0;
    foreach ($g['emails'] as $a) {
      if ($i) print ', ';
      print ('<A HREF="mailto:' .
             esc_url($a['value']) . '">' .
             htmlspecialchars($a['value']) . '</A>');
      $i++;
    }

    if ($g['libravatar'] ?? null) {
      print ' <I>(libravatar)</I>';
    }

    print '<BR>';
  }

  if ($g['phoneNumbers'] ?? null) {
    $i = 0;
    foreach ($g['phoneNumbers'] as $a) {
      if ($i) print ', ';
      print htmlspecialchars($a['type']) . ': ';
      print ('<A HREF="tel:' .
             esc_url($a['value']) . '">' .
             htmlspecialchars($a['value']) . '</A>');
      $i++;
    }
    print '<BR>';
  }

  if ($g['ims'] ?? null) {
    $i = 0;
    foreach ($g['ims'] as $a) {
      if ($i) print ', ';
      print htmlspecialchars($a['type']) . ': ';
      print htmlspecialchars($a['value']);
      $i++;
    }
    print '<BR>';
  }

  if ($g['accounts'] ?? null) {
    $i = 0;
    foreach ($g['accounts'] as $a) {
      if ($i) print ', ';
      print ('<A HREF="' . esc_url($a['url']) . '">' .
             htmlspecialchars ($a['shortname'] == 'twitter'
                               ? '@' . $a['username']
                               : $a['shortname']) .
             '</A>');
      $i++;
    }
    print '<BR>';
  }

  if ($g['urls'] ?? null) {
    $i = 0;
    foreach ($g['urls'] as $a) {
      if ($i) print ', ';
      print ('<A HREF="' . esc_url($a['value']) . '">' .
             htmlspecialchars ($a['title'] ?? $a['value']) . '</A>');
      $i++;
    }
    print '<BR>';
  }

  if (($g['mastodon'] ?? null) && ($g['fields'] ?? null)) {
    foreach ($g['fields'] as $f) {
      $k = $f['name'];
      $v = $f['value'];
      if (preg_match ('/href="(.*?)"/i', $v, $m))
        $v = $m[1];
      else
        $v = null;

      if ($v) print '<A HREF="' . htmlspecialchars($v) . '">';
      print htmlspecialchars($k);
      if ($v) print '</A>';
      if ($f['verified_at']) print ' <I>(verified)</I>';
      print '<BR>';
    }
  }

  if ($g['aboutMe'] ?? null)
    print ('<P>' .
           preg_replace('/\n/s', '<BR>', 
             htmlspecialchars (html_entity_decode ($g['aboutMe']))) .
           '<BR>');

  if (($g['mastodon'] ?? null) && ($g['note'] ?? null)) {
    print htmlspecialchars (
      wp_specialchars_decode (wp_strip_all_tags ($g['note']), ENT_QUOTES));
    print "<BR>";
  }

  print "<DIV STYLE='clear:right'></DIV>";
}
