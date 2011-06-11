<?php
/*
Plugin Name: Blogger Importer
Plugin URI: http://wordpress.org/extend/plugins/blogger-importer/
Description: Import posts, comments, tags, and attachments from a Blogger blog.
Author: wordpressdotorg
Author URI: http://wordpress.org/
Version: 0.4
License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

// Load Simple Pie
require_once ABSPATH . WPINC . '/class-feed.php';

// Load OAuth library
require_once 'oauth.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * How many records per GData query
 *
 * @package WordPress
 * @subpackage Blogger_Import
 * @var int
 * @since unknown
 */
define( 'MAX_RESULTS',        50 );

/**
 * How many seconds to let the script run
 *
 * @package WordPress
 * @subpackage Blogger_Import
 * @var int
 * @since unknown
 */
define( 'MAX_EXECUTION_TIME', 20 );

/**
 * How many seconds between status bar updates
 *
 * @package WordPress
 * @subpackage Blogger_Import
 * @var int
 * @since unknown
 */
define( 'STATUS_INTERVAL',     3 );

/**
 * Blogger Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class Blogger_Import extends WP_Importer {

	// Shows the welcome screen and the magic auth link.
	function greet() {
		$next_url = get_option('siteurl') . '/wp-admin/index.php?import=blogger&amp;noheader=true';
		$auth_url = $this->get_oauth_link();
		$title = __('Import Blogger', 'blogger-importer');
		$welcome = __('Howdy! This importer allows you to import posts and comments from your Blogger account into your WordPress site.', 'blogger-importer');
		$prereqs = __('To use this importer, you must have a Google account and an upgraded (New, was Beta) blog hosted on blogspot.com or a custom domain (not FTP).', 'blogger-importer');
		$stepone = __('The first thing you need to do is tell Blogger to let WordPress access your account. You will be sent back here after providing authorization.', 'blogger-importer');
		$auth = esc_attr__('Authorize', 'blogger-importer');

		echo "
		<div class='wrap'>
		".screen_icon()."
		<h2>$title</h2>
		<p>$welcome</p><p>$prereqs</p><p>$stepone</p>
			<form action='{$auth_url['url']}' method='get'>
				<p class='submit' style='text-align:left;'>
					<input type='submit' class='button' value='$auth' />
					<input type='hidden' name='oauth_token' value='{$auth_url['oauth_token']}' />
					<input type='hidden' name='oauth_callback' value='{$auth_url['oauth_callback']}' />
				</p>
			</form>
		</div>\n";
	}
	
	function get_oauth_link() {
		// Establish an Blogger_OAuth consumer
		$base_url = get_option('siteurl') . '/wp-admin';
		$request_token_endpoint = 'https://www.google.com/accounts/OAuthGetRequestToken';
		$authorize_endpoint = 'https://www.google.com/accounts/OAuthAuthorizeToken';

		$test_consumer = new Blogger_OAuthConsumer('anonymous', 'anonymous', NULL); // anonymous is a google thing to allow non-registered apps to work

		//prepare to get request token
		$sig_method = new Blogger_OAuthSignatureMethod_HMAC_SHA1();
		$parsed = parse_url($request_token_endpoint);
		$params = array('callback' => $base_url, 'scope'=>'http://www.blogger.com/feeds/', 'xoauth_displayname'=>'WordPress');

		$req_req = Blogger_OAuthRequest::from_consumer_and_token($test_consumer, NULL, "GET", $request_token_endpoint, $params);
		$req_req->sign_request($sig_method, $test_consumer, NULL);

		// go get the request tokens from Google
		$req_token = wp_remote_retrieve_body(wp_remote_get($req_req->to_url(), array('sslverify'=>false) ) );

		// parse the tokens
		parse_str ($req_token,$tokens);

		$oauth_token = $tokens['oauth_token'];
		$oauth_token_secret = $tokens['oauth_token_secret'];

		$callback_url = "$base_url/index.php?import=blogger&noheader=true&token=$oauth_token&token_secret=$oauth_token_secret";

		return array('url'=>$authorize_endpoint, 'oauth_token'=>$oauth_token, 'oauth_callback'=>$callback_url );	
	}

	function uh_oh($title, $message, $info) {
		echo "<div class='wrap'>";
		screen_icon();
		echo "<h2>$title</h2><p>$message</p><pre>$info</pre></div>";
	}

	function auth() {
		// we have a authorized request token now, so upgrade it to an access token		
		$token = $_GET['token'];
		$token_secret = $_GET['token_secret'];
		
		$oauth_access_token_endpoint  = 'https://www.google.com/accounts/OAuthGetAccessToken';
		
		// auth the token
		$test_consumer = new Blogger_OAuthConsumer('anonymous', 'anonymous', NULL);
		$auth_token = new Blogger_OAuthConsumer($token, $token_secret);
		$access_token_req = new Blogger_OAuthRequest("GET", $oauth_access_token_endpoint);
		$access_token_req = $access_token_req->from_consumer_and_token($test_consumer, $auth_token, "GET", $oauth_access_token_endpoint);
		
		$access_token_req->sign_request(new Blogger_OAuthSignatureMethod_HMAC_SHA1(),$test_consumer, $auth_token);
		
		$after_access_request = wp_remote_retrieve_body(wp_remote_get($access_token_req->to_url(), array('sslverify'=>false) ) );
		
		parse_str($after_access_request,$access_tokens);
		
		$this->token = $access_tokens['oauth_token'];
		$this->token_secret = $access_tokens['oauth_token_secret'];
	
		wp_redirect( remove_query_arg( array( 'token', 'noheader' ) ) );
	}
	
	// get a URL using the oauth token for authentication (returns false on failure)
	function oauth_get($url, $params=NULL) {
		$test_consumer = new Blogger_OAuthConsumer('anonymous', 'anonymous', NULL);
		$goog = new Blogger_OAuthConsumer($this->token, $this->token_secret, NULL);
		$request = new Blogger_OAuthRequest("GET", $url, $params);

		$blog_req = $request->from_consumer_and_token($test_consumer, $goog, 'GET', $url);

		$blog_req->sign_request(new Blogger_OAuthSignatureMethod_HMAC_SHA1(),$test_consumer,$goog);

		$data = wp_remote_get($blog_req->to_url(), array('sslverify'=>false) );
		
		if ( wp_remote_retrieve_response_code( $data ) == 200 ) {
			$response = wp_remote_retrieve_body( $data );
		} else {
			$response == false;
		}
		
		return $response;
	}
	
	function show_blogs($iter = 0) {
		if ( empty($this->blogs) ) {
			$xml = $this->oauth_get('https://www.blogger.com/feeds/default/blogs');

			// Give it a few retries... this step often flakes out the first time.
			if ( empty( $xml ) ) {
				if ( $iter < 3 ) {
					return $this->show_blogs($iter + 1);
				} else {
					$this->uh_oh(
						__('Trouble signing in', 'blogger-importer'),
						__('We were not able to gain access to your account. Try starting over.', 'blogger-importer'),
						''
					);
					return false;
				}
			}
			
			$feed = new SimplePie();
			$feed->set_raw_data($xml);
			$feed->init();
			
			foreach ($feed->get_items() as $item) {
				$blog['title'] = $item->get_title();
				$blog['summary'] = $item->get_description();
				
				$parts = parse_url( $item->get_link( 0, 'alternate' ) );
				$blog['host'] = $parts['host'];
				
				$blog['gateway'] = $item->get_link( 0, 'edit' );
				
				$blog['posts_url'] = $item->get_link( 0, 'http://schemas.google.com/g/2005#post' );
				
				if ( ! empty ( $blog ) ) {
					$blog['total_posts'] = $this->get_total_results( $blog['posts_url'] );
					$blog['total_comments'] = $this->get_total_results( "http://{$blog['host']}/feeds/comments/default");
					$blog['mode'] = 'init';
					$this->blogs[] = $blog;
				}

			}
			
			if ( empty( $this->blogs ) ) {
				$this->uh_oh(
					__('No blogs found', 'blogger-importer'),
					__('We were able to log in but there were no blogs. Try a different account next time.', 'blogger-importer'),
					''
				);
				return false;
			}			
		}
//echo '<pre>'.print_r($this,1).'</pre>';
		$start    = esc_js( __('Import', 'blogger-importer') );
		$continue = esc_js( __('Continue', 'blogger-importer') );
		$stop     = esc_js( __('Importing...', 'blogger-importer') );
		$authors  = esc_js( __('Set Authors', 'blogger-importer') );
		$loadauth = esc_js( __('Preparing author mapping form...', 'blogger-importer') );
		$authhead = esc_js( __('Final Step: Author Mapping', 'blogger-importer') );
		$nothing  = esc_js( __('Nothing was imported. Had you already imported this blog?', 'blogger-importer') );
		$stopping = ''; //Missing String used below.
		$title    = __('Blogger Blogs', 'blogger-importer');
		$name     = __('Blog Name', 'blogger-importer');
		$url      = __('Blog URL', 'blogger-importer');
		$action   = __('The Magic Button', 'blogger-importer');
		$posts    = __('Posts', 'blogger-importer');
		$comments = __('Comments', 'blogger-importer');
		$noscript = __('This feature requires Javascript but it seems to be disabled. Please enable Javascript and then reload this page. Don&#8217;t worry, you can turn it back off when you&#8217;re done.', 'blogger-importer');

		$interval = STATUS_INTERVAL * 1000;

		foreach ( $this->blogs as $i => $blog ) {
			if ( $blog['mode'] == 'init' )
				$value = $start;
			elseif ( $blog['mode'] == 'posts' || $blog['mode'] == 'comments' )
				$value = $continue;
			else
				$value = $authors;
			$value = esc_attr($value);
			$blogtitle = esc_js( $blog['title'] );
			$pdone = isset($blog['posts_done']) ? (int) $blog['posts_done'] : 0;
			$cdone = isset($blog['comments_done']) ? (int) $blog['comments_done'] : 0;
			$init .= "blogs[$i]=new blog($i,'$blogtitle','{$blog['mode']}'," . $this->get_js_status($i) . ');';
			$pstat = "<div class='ind' id='pind$i'>&nbsp;</div><div id='pstat$i' class='stat'>$pdone/{$blog['total_posts']}</div>";
			$cstat = "<div class='ind' id='cind$i'>&nbsp;</div><div id='cstat$i' class='stat'>$cdone/{$blog['total_comments']}</div>";
			$rows .= "<tr id='blog$i'><td class='blogtitle'>$blogtitle</td><td class='bloghost'>{$blog['host']}</td><td class='bar'>$pstat</td><td class='bar'>$cstat</td><td class='submit'><input type='submit' class='button' id='submit$i' value='$value' /><input type='hidden' name='blog' value='$i' /></td></tr>\n";
		}

		echo "<div class='wrap'><h2>$title</h2><noscript>$noscript</noscript><table cellpadding='5px'><thead><tr><td>$name</td><td>$url</td><td>$posts</td><td>$comments</td><td>$action</td></tr></thead>\n$rows</table></div>";
		echo "
		<script type='text/javascript'>
		/* <![CDATA[ */
			var strings = {cont:'$continue',stop:'$stop',stopping:'$stopping',authors:'$authors',nothing:'$nothing'};
			var blogs = {};
			function blog(i, title, mode, status){
				this.blog   = i;
				this.mode   = mode;
				this.title  = title;
				this.status = status;
				this.button = document.getElementById('submit'+this.blog);
			};
			blog.prototype = {
				start: function() {
					this.cont = true;
					this.kick();
					this.check();
				},
				kick: function() {
					++this.kicks;
					var i = this.blog;
					jQuery.post('admin.php?import=blogger&noheader=true',{blog:this.blog},function(text,result){blogs[i].kickd(text,result)});
				},
				check: function() {
					++this.checks;
					var i = this.blog;
					jQuery.post('admin.php?import=blogger&noheader=true&status=true',{blog:this.blog},function(text,result){blogs[i].checkd(text,result)});
				},
				kickd: function(text, result) {
					if ( result == 'error' ) {
						// TODO: exception handling
						if ( this.cont )
							setTimeout('blogs['+this.blog+'].kick()', 1000);
					} else {
						if ( text == 'done' ) {
							this.stop();
							this.done();
						} else if ( text == 'nothing' ) {
							this.stop();
							this.nothing();
						} else if ( text == 'continue' ) {
							this.kick();
						} else if ( this.mode = 'stopped' )
							jQuery(this.button).attr('value', strings.cont);
					}
					--this.kicks;
				},
				checkd: function(text, result) {
					if ( result == 'error' ) {
						// TODO: exception handling
					} else {
						eval('this.status='+text);
						jQuery('#pstat'+this.blog).empty().append(this.status.p1+'/'+this.status.p2);
						jQuery('#cstat'+this.blog).empty().append(this.status.c1+'/'+this.status.c2);
						this.update();
						if ( this.cont || this.kicks > 0 )
							setTimeout('blogs['+this.blog+'].check()', $interval);
					}
					--this.checks;
				},
				update: function() {
					jQuery('#pind'+this.blog).width(((this.status.p1>0&&this.status.p2>0)?(this.status.p1/this.status.p2*jQuery('#pind'+this.blog).parent().width()):1)+'px');
					jQuery('#cind'+this.blog).width(((this.status.c1>0&&this.status.c2>0)?(this.status.c1/this.status.c2*jQuery('#cind'+this.blog).parent().width()):1)+'px');
				},
				stop: function() {
					this.cont = false;
				},
				done: function() {
					this.mode = 'authors';
					jQuery(this.button).attr('value', strings.authors);
				},
				nothing: function() {
					this.mode = 'nothing';
					jQuery(this.button).remove();
					alert(strings.nothing);
				},
				getauthors: function() {
					if ( jQuery('div.wrap').length > 1 )
						jQuery('div.wrap').gt(0).remove();
					jQuery('div.wrap').empty().append('<h2>$authhead</h2><h3>' + this.title + '</h3>');
					jQuery('div.wrap').append('<p id=\"auth\">$loadauth</p>');
					jQuery('p#auth').load('index.php?import=blogger&noheader=true&authors=1',{blog:this.blog});
				},
				init: function() {
					this.update();
					var i = this.blog;
					jQuery(this.button).bind('click', function(){return blogs[i].click();});
					this.kicks = 0;
					this.checks = 0;
				},
				click: function() {
					if ( this.mode == 'init' || this.mode == 'stopped' || this.mode == 'posts' || this.mode == 'comments' ) {
						this.mode = 'started';
						this.start();
						jQuery(this.button).attr('value', strings.stop);
					} else if ( this.mode == 'started' ) {
						return false; // let it run...
						this.mode = 'stopped';
						this.stop();
						if ( this.checks > 0 || this.kicks > 0 ) {
							this.mode = 'stopping';
							jQuery(this.button).attr('value', strings.stopping);
						} else {
							jQuery(this.button).attr('value', strings.cont);
						}
					} else if ( this.mode == 'authors' ) {
						document.location = 'index.php?import=blogger&authors=1&blog='+this.blog;
						//this.mode = 'authors2';
						//this.getauthors();
					}
					return false;
				}
			};
			$init
			jQuery.each(blogs, function(i, me){me.init();});
		/* ]]> */
		</script>\n";
	}

	// Handy function for stopping the script after a number of seconds.
	function have_time() {
		global $importer_started;
		if ( time() - $importer_started > MAX_EXECUTION_TIME )
			die('continue');
		return true;
	}
	
	function get_total_results($url) {		
		$response = $this->oauth_get( $url, array('max-results'=>1, 'start-index'=>2) );
		$parser = xml_parser_create();
		xml_parse_into_struct($parser, $response, $struct, $index);
		xml_parser_free($parser);
		$total_results = $struct[$index['OPENSEARCH:TOTALRESULTS'][0]]['value'];
		return (int) $total_results;
	}

	function import_blog($blogID) {
		global $importing_blog;
		$importing_blog = $blogID;

		if ( isset($_GET['authors']) )
			return print($this->get_author_form());

		header('Content-Type: text/plain');

		if ( isset($_GET['status']) )
			die($this->get_js_status());

		if ( isset($_GET['saveauthors']) )
			die($this->save_authors());

		$blog = $this->blogs[$blogID];

		$total_results = $this->get_total_results($blog['posts_url']);
		$this->blogs[$importing_blog]['total_posts'] = $total_results;

		$start_index = $total_results - MAX_RESULTS + 1;

		if ( isset( $this->blogs[$importing_blog]['posts_start_index'] ) )
			$start_index = (int) $this->blogs[$importing_blog]['posts_start_index'];
		elseif ( $total_results > MAX_RESULTS )
			$start_index = $total_results - MAX_RESULTS + 1;
		else
			$start_index = 1;

		// This will be positive until we have finished importing posts
		if ( $start_index > 0 ) {
			// Grab all the posts
			$this->blogs[$importing_blog]['mode'] = 'posts';
			do {
				$index = $struct = $entries = array();

				$url = $blog['posts_url'];
				$response = $this->oauth_get( $url, array('max-results'=>MAX_RESULTS, 'start-index'=>$start_index) );
				
				if ($response == false) break;
								
				// parse the feed
				$feed = new SimplePie();
				$feed->set_raw_data($response);
				$feed->init();
				
				foreach ( $feed->get_items() as $item ) {
					
					$blogentry = new BloggerEntry();
					
					$blogentry->id = $item->get_id();
					$blogentry->published = simplepie_get_single_item($item, 'published');
					$blogentry->updated = simplepie_get_single_item($item, 'updated');
					$blogentry->title = $item->get_title();
					$blogentry->content = $item->get_content();
					$blogentry->author = $item->get_author()->get_name();
					
					$linktypes = array('replies','edit','self','alternate');
					foreach ($linktypes as $type) {
						$links = $item->get_links($type);
						foreach ($links as $link) {
							$blogentry->links[] = array( 'rel' => $type, 'href' => $link );
						}
					}
					
					$cats = $item->get_categories();
					foreach ($cats as $cat) {
						$blogentry->categories[] = $cat->term;
					}
					
					$result = $this->import_post($blogentry);
				}
				
				// Get the 'previous' query string which we'll use on the next iteration
				$query = '';
				$links = preg_match_all('/<link([^>]*)>/', $response, $matches);
				if ( count( $matches[1] ) )
					foreach ( $matches[1] as $match )
						if ( preg_match('/rel=.previous./', $match) )
							$query = @html_entity_decode( preg_replace('/^.*href=[\'"].*\?(.+)[\'"].*$/', '$1', $match), ENT_COMPAT, get_option('blog_charset') );

				if ( $query ) {
					parse_str($query, $q);
					$this->blogs[$importing_blog]['posts_start_index'] = (int) $q['start-index'];
				} else
					$this->blogs[$importing_blog]['posts_start_index'] = 0;
				$this->save_vars();
			} while ( !empty( $query ) && $this->have_time() );
		}

		$total_results = $this->get_total_results( "http://{$blog['host']}/feeds/comments/default" );
		$this->blogs[$importing_blog]['total_comments'] = $total_results;

		if ( isset( $this->blogs[$importing_blog]['comments_start_index'] ) )
			$start_index = (int) $this->blogs[$importing_blog]['comments_start_index'];
		elseif ( $total_results > MAX_RESULTS )
			$start_index = $total_results - MAX_RESULTS + 1;
		else
			$start_index = 1;

		if ( $start_index > 0 ) {
			// Grab all the comments
			$this->blogs[$importing_blog]['mode'] = 'comments';
			do {
				$index = $struct = $entries = array();

				$response = $this->oauth_get( "http://{$blog['host']}/feeds/comments/default", array('max-results'=>MAX_RESULTS, 'start-index'=>$start_index) );
				
				// parse the feed
				$feed = new SimplePie();
				$feed->set_raw_data($response);
				$feed->init();

				foreach ( $feed->get_items() as $item ) {

					$blogentry = new BloggerEntry();
					
					$blogentry->updated = simplepie_get_single_item($item, 'updated');
					$blogentry->content = $item->get_content();
					$blogentry->author = $item->get_author()->get_name();
					$blogentry->authoruri = $item->get_author()->get_link();
					$blogentry->authoremail = $item->get_author()->get_email();
					
					$temp = $item->get_item_tags('http://purl.org/syndication/thread/1.0','in-reply-to');
					foreach ($temp as $t) {
						if ( isset( $t['attribs']['']['source'] ) ) {
							$blogentry->source = $t['attribs']['']['source'];
						}
					}
					
					$this->import_comment($blogentry);
				}
				
				// Get the 'previous' query string which we'll use on the next iteration
				$query = '';
				$links = preg_match_all('/<link([^>]*)>/', $response, $matches);
				if ( count( $matches[1] ) )
					foreach ( $matches[1] as $match )
						if ( preg_match('/rel=.previous./', $match) )
							$query = @html_entity_decode( preg_replace('/^.*href=[\'"].*\?(.+)[\'"].*$/', '$1', $match), ENT_COMPAT, get_option('blog_charset') );

				parse_str($query, $q);

				$this->blogs[$importing_blog]['comments_start_index'] = (int) $q['start-index'];
				$this->save_vars();
			} while ( !empty( $query ) && $this->have_time() );
		}
		$this->blogs[$importing_blog]['mode'] = 'authors';
		$this->save_vars();
		if ( !$this->blogs[$importing_blog]['posts_done'] && !$this->blogs[$importing_blog]['comments_done'] )
			die('nothing');
		do_action('import_done', 'blogger');
		die('done');
	}

	function convert_date( $date ) {
	    preg_match('#([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]+)?(Z|[\+|\-][0-9]{2,4}){0,1}#', $date, $date_bits);
	    $offset = iso8601_timezone_to_offset( $date_bits[7] );
		$timestamp = gmmktime($date_bits[4], $date_bits[5], $date_bits[6], $date_bits[2], $date_bits[3], $date_bits[1]);
		$timestamp -= $offset; // Convert from Blogger local time to GMT
		$timestamp += get_option('gmt_offset') * 3600; // Convert from GMT to WP local time
		return gmdate('Y-m-d H:i:s', $timestamp);
	}

	function no_apos( $string ) {
		return str_replace( '&apos;', "'", $string);
	}

	function min_whitespace( $string ) {
		return preg_replace( '|\s+|', ' ', $string );
	}

	function _normalize_tag( $matches ) {
		return '<' . strtolower( $matches[1] );
	}

	function import_post( $entry ) {
		global $importing_blog;

		// The old permalink is all Blogger gives us to link comments to their posts.
		if ( isset( $entry->draft ) )
			$rel = 'self';
		else
			$rel = 'alternate';

		foreach ( $entry->links as $link ) {			
			// save the self link as meta
			if ( $link['rel'] == 'self' ) {
				$postself = $link['href'];
				$parts = parse_url( $link['href'] );
				$entry->old_permalink = $parts['path'];
				
			}

			// save the replies feed link as meta (ignore the comment form one)
			if ( $link['rel'] == 'replies' && false === strpos($link['href'], '#comment-form') ) {
				$postreplies = $link['href'];
			}
		}
		
		$post_date    = $this->convert_date( $entry->published );
		$post_content = trim( addslashes( $this->no_apos( @html_entity_decode( $entry->content, ENT_COMPAT, get_option('blog_charset') ) ) ) );
		$post_title   = trim( addslashes( $this->no_apos( $this->min_whitespace( $entry->title ) ) ) );
		$post_status  = isset( $entry->draft ) ? 'draft' : 'publish';

		// Clean up content
		$post_content = preg_replace_callback('|<(/?[A-Z]+)|', array( &$this, '_normalize_tag' ), $post_content);
		$post_content = str_replace('<br>', '<br />', $post_content);
		$post_content = str_replace('<hr>', '<hr />', $post_content);

		// Checks for duplicates
		if ( isset( $this->blogs[$importing_blog]['posts'][$entry->old_permalink] ) ) {
			++$this->blogs[$importing_blog]['posts_skipped'];
		} elseif ( $post_id = post_exists( $post_title, $post_content, $post_date ) ) {
			$this->blogs[$importing_blog]['posts'][$entry->old_permalink] = $post_id;
			++$this->blogs[$importing_blog]['posts_skipped'];
		} else {
			$post = compact('post_date', 'post_content', 'post_title', 'post_status');

			$post_id = wp_insert_post($post);
			if ( is_wp_error( $post_id ) )
				return $post_id;

			wp_create_categories( array_map( 'addslashes', $entry->categories ), $post_id );

			$author = $this->no_apos( strip_tags( $entry->author ) );

			add_post_meta( $post_id, 'blogger_blog', $this->blogs[$importing_blog]['host'], true );
			add_post_meta( $post_id, 'blogger_author', $author, true );
			add_post_meta( $post_id, 'blogger_permalink', $entry->old_permalink, true );
			add_post_meta( $post_id, '_blogger_self', $postself, true );
			add_post_meta( $post_id, '_blogger_replies', $postreplies, true );

			$this->blogs[$importing_blog]['posts'][$entry->old_permalink] = $post_id;
			++$this->blogs[$importing_blog]['posts_done'];
		}
		$this->save_vars();
		return;
	}

	function import_comment( $entry ) {
		global $importing_blog;
		
		$parts = parse_url( $entry->source );
		$entry->old_post_permalink = $parts['path'];
		
		// Drop the #fragment and we have the comment's old post permalink.
		foreach ( $entry->links as $link ) {
			if ( $link['rel'] == 'alternate' ) {
				$parts = parse_url( $link['href'] );
				$entry->old_permalink = $parts['fragment'];
				break;
			}
		}
				
		$comment_post_ID = (int) $this->blogs[$importing_blog]['posts'][$entry->old_post_permalink];
		$comment_author  = addslashes( $this->no_apos( strip_tags( $entry->author ) ) );
		$comment_author_url = addslashes( $this->no_apos( strip_tags( $entry->authoruri ) ) );
		$comment_author_email = addslashes( $this->no_apos( strip_tags( $entry->authoremail ) ) );
		$comment_date    = $this->convert_date( $entry->updated );
		$comment_content = addslashes( $this->no_apos( @html_entity_decode( $entry->content, ENT_COMPAT, get_option('blog_charset') ) ) );

		// Clean up content
		$comment_content = preg_replace_callback('|<(/?[A-Z]+)|', array( &$this, '_normalize_tag' ), $comment_content);
		$comment_content = str_replace('<br>', '<br />', $comment_content);
		$comment_content = str_replace('<hr>', '<hr />', $comment_content);

		// Checks for duplicates
		if (
			isset( $this->blogs[$importing_blog]['comments'][$entry->old_permalink] ) ||
			comment_exists( $comment_author, $comment_date )
		) {
			++$this->blogs[$importing_blog]['comments_skipped'];
		} else {
			$comment = compact('comment_post_ID', 'comment_author', 'comment_author_url', 'comment_date', 'comment_content');

			$comment = wp_filter_comment($comment);
			$comment_id = wp_insert_comment($comment);

			$this->blogs[$importing_blog]['comments'][$entry->old_permalink] = $comment_id;

			++$this->blogs[$importing_blog]['comments_done'];
		}
		$this->save_vars();
	}

	function get_js_status($blog = false) {
		global $importing_blog;
		if ( $blog === false )
			$blog = $this->blogs[$importing_blog];
		else
			$blog = $this->blogs[$blog];
		$p1 = isset( $blog['posts_done'] ) ? (int) $blog['posts_done'] : 0;
		$p2 = isset( $blog['total_posts'] ) ? (int) $blog['total_posts'] : 0;
		$c1 = isset( $blog['comments_done'] ) ? (int) $blog['comments_done'] : 0;
		$c2 = isset( $blog['total_comments'] ) ? (int) $blog['total_comments'] : 0;
		return "{p1:$p1,p2:$p2,c1:$c1,c2:$c2}";
	}

	function get_author_form($blog = false) {
		global $importing_blog, $wpdb, $current_user;
		if ( $blog === false )
			$blog = & $this->blogs[$importing_blog];
		else
			$blog = & $this->blogs[$blog];

		if ( !isset( $blog['authors'] ) ) {
			$post_ids = array_values($blog['posts']);
			$authors = (array) $wpdb->get_col("SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = 'blogger_author' AND post_id IN (" . join( ',', $post_ids ) . ")");
			$blog['authors'] = array_map(null, $authors, array_fill(0, count($authors), $current_user->ID));
			$this->save_vars();
		}

		$directions = sprintf( __('All posts were imported with the current user as author. Use this form to move each Blogger user&#8217;s posts to a different WordPress user. You may <a href="%s">add users</a> and then return to this page and complete the user mapping. This form may be used as many times as you like until you activate the &#8220;Restart&#8221; function below.', 'blogger-importer'), 'users.php' );
		$heading = __('Author mapping', 'blogger-importer');
		$blogtitle = "{$blog['title']} ({$blog['host']})";
		$mapthis = __('Blogger username', 'blogger-importer');
		$tothis = __('WordPress login', 'blogger-importer');
		$submit = esc_js( __('Save Changes', 'blogger-importer') );

		foreach ( $blog['authors'] as $i => $author )
			$rows .= "<tr><td><label for='authors[$i]'>{$author[0]}</label></td><td><select name='authors[$i]' id='authors[$i]'>" . $this->get_user_options($author[1]) . "</select></td></tr>";

		return "<div class='wrap'><h2>$heading</h2><h3>$blogtitle</h3><p>$directions</p><form action='index.php?import=blogger&amp;noheader=true&saveauthors=1' method='post'><input type='hidden' name='blog' value='" . esc_attr($importing_blog) . "' /><table cellpadding='5'><thead><td>$mapthis</td><td>$tothis</td></thead>$rows<tr><td></td><td class='submit'><input type='submit' class='button authorsubmit' value='$submit' /></td></tr></table></form></div>";
	}

	function get_user_options($current) {
		global $importer_users;
		if ( ! isset( $importer_users ) )
			$importer_users = (array) get_users_of_blog();

		foreach ( $importer_users as $user ) {
			$sel = ( $user->user_id == $current ) ? " selected='selected'" : '';
			$options .= "<option value='$user->user_id'$sel>$user->display_name</option>";
		}

		return $options;
	}

	function save_authors() {
		global $importing_blog, $wpdb;
		$authors = (array) $_POST['authors'];

		$host = $this->blogs[$importing_blog]['host'];

		// Get an array of posts => authors
		$post_ids = (array) $wpdb->get_col( $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'blogger_blog' AND meta_value = %s", $host) );
		$post_ids = join( ',', $post_ids );
		$results = (array) $wpdb->get_results("SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = 'blogger_author' AND post_id IN ($post_ids)");
		foreach ( $results as $row )
			$authors_posts[$row->post_id] = $row->meta_value;

		foreach ( $authors as $author => $user_id ) {
			$user_id = (int) $user_id;

			// Skip authors that haven't been changed
			if ( $user_id == $this->blogs[$importing_blog]['authors'][$author][1] )
				continue;

			// Get a list of the selected author's posts
			$post_ids = (array) array_keys( $authors_posts, $this->blogs[$importing_blog]['authors'][$author][0] );
			$post_ids = join( ',', $post_ids);

			$wpdb->query( $wpdb->prepare("UPDATE $wpdb->posts SET post_author = %d WHERE id IN ($post_ids)", $user_id) );
			$this->blogs[$importing_blog]['authors'][$author][1] = $user_id;
		}
		$this->save_vars();

		wp_redirect('edit.php');
	}

	function restart() {
		global $wpdb;
		$options = get_option( 'blogger_importer' );

		delete_option('blogger_importer');
		$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = 'blogger_author'");
		wp_redirect('?import=blogger');
	}

	// Step 9: Congratulate the user
	function congrats() {
		$blog = (int) $_GET['blog'];
		echo '<h1>'.__('Congratulations!', 'blogger-importer').'</h1><p>'.__('Now that you have imported your Blogger blog into WordPress, what are you going to do? Here are some suggestions:', 'blogger-importer').'</p><ul><li>'.__('That was hard work! Take a break.', 'blogger-importer').'</li>';
		if ( count($this->import['blogs']) > 1 )
			echo '<li>'.__('In case you haven&#8217;t done it already, you can import the posts from your other blogs:', 'blogger-importer'). $this->show_blogs() . '</li>';
		if ( $n = count($this->import['blogs'][$blog]['newusers']) )
			echo '<li>'.sprintf(__('Go to <a href="%s" target="%s">Authors &amp; Users</a>, where you can modify the new user(s) or delete them. If you want to make all of the imported posts yours, you will be given that option when you delete the new authors.', 'blogger-importer'), 'users.php', '_parent').'</li>';
		echo '<li>'.__('For security, click the link below to reset this importer.', 'blogger-importer').'</li>';
		echo '</ul>';
	}

	// Figures out what to do, then does it.
	function start() {
		if ( isset($_POST['restart']) )
			$this->restart();
			
		$options = get_option('blogger_importer');

		if ( is_array($options) )
			foreach ( $options as $key => $value )
				$this->$key = $value;

		if ( isset( $_REQUEST['blog'] ) ) {
			$blog = is_array($_REQUEST['blog']) ? array_shift( $keys = array_keys( $_REQUEST['blog'] ) ) : $_REQUEST['blog'];
			$blog = (int) $blog;
			$result = $this->import_blog( $blog );
			if ( is_wp_error( $result ) )
				echo $result->get_error_message();
		} elseif ( isset($_GET['token']) && isset($_GET['token_secret']) )
			$this->auth();
		elseif ( isset($this->token) && isset($this->token_secret) )
			$this->show_blogs();
		else
			$this->greet();

		$saved = $this->save_vars();

		if ( $saved && !isset($_GET['noheader']) ) {
			$restart = __('Restart', 'blogger-importer');
			$message = __('We have saved some information about your Blogger account in your WordPress database. Clearing this information will allow you to start over. Restarting will not affect any posts you have already imported. If you attempt to re-import a blog, duplicate posts and comments will be skipped.', 'blogger-importer');
			$submit = esc_attr__('Clear account information', 'blogger-importer');
			echo "<div class='wrap'><h2>$restart</h2><p>$message</p><form method='post' action='?import=blogger&amp;noheader=true'><p class='submit' style='text-align:left;'><input type='submit' class='button' value='$submit' name='restart' /></p></form></div>";
		}
	}

	function save_vars() {
		$vars = get_object_vars($this);
		update_option( 'blogger_importer', $vars );

		return !empty($vars);
	}

	function admin_head() {
?>
<style type="text/css">
td { text-align: center; line-height: 2em;}
thead td { font-weight: bold; }
.bar {
	width: 200px;
	text-align: left;
	line-height: 2em;
	padding: 0px;
}
.ind {
	position: absolute;
	background-color: #83B4D8;
	width: 1px;
	z-index: 9;
}
.stat {
	z-index: 10;
	position: relative;
	text-align: center;
}
td.submit {
	margin:0;
	padding:0;
}

td {
	padding-left:10px;
	padding-right:10px;
}
</style>
<?php
	}

	function Blogger_Import() {
		global $importer_started;
		$importer_started = time();
		if ( isset( $_GET['import'] ) && $_GET['import'] == 'blogger' ) {
			wp_enqueue_script('jquery');
			add_action('admin_head', array(&$this, 'admin_head'));
		}
	}
}

$blogger_import = new Blogger_Import();

register_importer('blogger', __('Blogger', 'blogger-importer'), __('Import posts, comments, and users from a Blogger blog.', 'blogger-importer'), array ($blogger_import, 'start'));

class BloggerEntry {
	var $links = array();
	var $categories = array();
}

function simplepie_get_single_item($item, $tag) {
	$temparray = $item->get_item_tags(SIMPLEPIE_NAMESPACE_ATOM_10, $tag);
	if ( isset( $temparray[0]['data'] ) ) return $temparray[0]['data'];
	else return NULL;
}

} // class_exists( 'WP_Importer' )

function blogger_importer_init() {
    load_plugin_textdomain( 'blogger-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'blogger_importer_init' );
