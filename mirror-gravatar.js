/* Live-updating Gravatar preview when posting comments.

   Copyright © 2022-2024 Jamie Zawinski <jwz@jwz.org>

   Permission to use, copy, modify, distribute, and sell this software and its
   documentation for any purpose is hereby granted without fee, provided that
   the above copyright notice appear in all copies and that both that
   copyright notice and this permission notice appear in supporting
   documentation.  No representations are made about the suitability of this
   software for any purpose.  It is provided "as is" without express or 
   implied warranty.

   Created: 31-May-2022.

   Requires "crypto-js/sha256.js".
 */

(function() {

var default_gravatar = null;
var gravatar_size = 48;

// Figure out the avatars we're using, and set some event handlers.
//
function gravatar_preview_init() {
  var parent = document.getElementsByClassName('comment-form-gravatar')[0];
  var img    = (parent ? parent.getElementsByTagName('img')[0] : null);
  var email  = document.getElementById('email');

  if (email) {
    email.addEventListener ('focusout', gravatar_preview_update_image);
  }

  if (img) {
    default_gravatar = img.src;
    if (img.className.match(/\bavatar-(\d+)/))
      gravatar_size = RegExp.$1;
  }

  gravatar_preview_update_image();
}


// Update the gravatar image based on the current email address.
//
function gravatar_preview_update_image() {
  var parent = document.getElementsByClassName('comment-form-gravatar')[0];
  var img    = (parent ? parent.getElementsByTagName('img')[0] : null);
  var email  = document.getElementById('email');

  var addr = (email ? email.value : '');
  addr = addr.replace (/^\s+|\s+$/s, '').toLowerCase();
  if (!addr) return;

  // libravatar.org falls back to gravatar.com if not found; however,
  // libravatar.org does not allow URLs in "d=", so we can't point that
  // at our local fallback image.  Instead we get a black box.
  //
  var gravatar = ('https://seccdn.libravatar.org/avatar/' +
                  CryptoJS.SHA256(addr).toString() +
                  '?d=' +
                  '404' + // encodeURIComponent(default_gravatar) +
                  '&s=' + gravatar_size +
                  '&r=x');
  img.src = gravatar;
  mirror_gravatar_update (gravatar);
}


// If the current email address has no gravatar, explain how to get one.
// This assumes that a 'comment-form-gravatar' element has been added to
// the form via 'comment_form_defaults' or similar.
//
var gravatar_cache = {};
function mirror_gravatar_update (url) {

  var parent = document.getElementsByClassName('comment-form-gravatar')[0];
  var img    = (parent ? parent.getElementsByTagName('img')[0] : null);
  var blurb  = document.getElementById('comment_reply_gravatar_blurb');

  if (img)
    img.src = url;

  if (! url.match(
          /^https?:\/\/([^\/]*\.)?(gravatar\.com|libravatar\.org)\//si)) {
    // Probably still the default mystery image?
    blurb.style.display = 'block';
    return;
  }

  // Request a small image, or a 404 if there isn't one.  The JSON URLs are
  // cross-origin restricted, but the images themselves are not.
  url = url.replace (/&amp;/g, '&');
  url = url.replace(/([?&]s=)\d+/,    '$116');   // s=16
  url = url.replace(/([?&]d=)[^?&]+/, '$1404');  // d=404

  if (gravatar_cache[url] === true)
    blurb.style.display = 'none';
  else if (gravatar_cache[url] === false)
    blurb.style.display = 'block';
  else {
    gravatar_cache[url] = false;
    var conn = new XMLHttpRequest();
    conn.onreadystatechange = function() {
      if (this.readyState == 4) {
        gravatar_cache[url] = (this.status == 200);
        blurb.style.display = (this.status == 200 ? 'none' : 'block');
      }
    };
    conn.open ('GET', url, true);
    conn.send ();
  }
}


if (document.readyState === 'loading') {
  document.addEventListener ("DOMContentLoaded", gravatar_preview_init);
} else {
  gravatar_preview_init();
}

})();
