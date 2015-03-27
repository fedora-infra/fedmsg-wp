<?php
/**
 * Plugin Name: Fedora Infrastructure WordPress Fedmsg Connector
 * Plugin URI: https://github.com/fedora-infra/fedmsg-wp
 * Description: This plugin sends WordPress statistics and events to fedmsg.
 * Version: 0.0.0
 * Author: Chaoyi Zha
 * License: GNU GPL v2
 */
defined('ABSPATH') or die('Direct access disallowed.');
class fedmsg_emit
{

	// A utility function, taken from the comments at
	// http://php.net/manual/en/language.types.boolean.php
	private function to_bool($_val)
	{
		$_trueValues     = array(
			'yes',
			'y',
			'true'
		);
		$_forceLowercase = true;
		if (is_string($_val))
		{
			return (in_array(($_forceLowercase ? strtolower($_val) : $_val), $_trueValues));
		}
		else
		{
			return (boolean) $_val;
		}
	}
	// A utility function to recursively sort an associative
	// array by key.  Kind of like ordereddict from Python.
	// Used for encoding and signing messages.
	private function deep_ksort(&$arr)
	{
		ksort($arr);
		foreach ($arr as &$a)
		{
			if (is_array($a) && !empty($a))
			{
				deep_ksort($a);
			}
		}
	}
	private function initialize()
	{
		global $config, $queue;
		/* Load the config.  Create a publishing socket. */
		// Danger! Danger!
		$json   = shell_exec("fedmsg-config");
		$config = json_decode($json, true);
		/* Just make sure everything is sane with the fedmsg config */
		if (!array_key_exists('relay_inbound', $config))
		{
			echo ("fedmsg-config has no 'relay_inbound'");
			return false;
		}
		$context = new ZMQContext(1, true);
		$queue   = $context->getSocket(ZMQ::SOCKET_PUB, "pub-a-dub-dub");
		$queue->setSockOpt(ZMQ::SOCKOPT_LINGER, $config['zmq_linger']);
		if (is_array($config['relay_inbound']))
		{
			// API for fedmsg >= 0.5.2
			// TODO - be more robust here and if connecting to the first one fails, try
			// the next, and the next, and etc...
			$queue->connect($config['relay_inbound'][0]);
		}
		else
		{
			// API for fedmsg <= 0.5.1
			$queue->connect($config['relay_inbound']);
		}
		# Go to sleep for a brief moment.. just long enough to let our zmq socket
		# initialize.
		if (array_key_exists('post_init_sleep', $config))
		{
			usleep($config['post_init_sleep'] * 1000000);
		}
		return true;
	}
	# This is a reimplementation of the python code in fedmsg/crypto.py
	# That file is authoritative.  Changes there should be reflected here.
	function sign_message($message_obj)
	{
		global $config;
		# This is required so that the string we sign is identical in python and in
		# php.  Ordereddict is used there; ksort here.
		deep_ksort($message_obj);
		# It would be best to pass JSON_UNESCAPE_SLASHES as an option here, but it is
		# not available until php-5.4
		$message  = json_encode($message_obj);
		# In the meantime, we'll remove escaped slashes ourselves.  This is
		# necessary in order to produce the exact same encoding as python (so that our
		# signatures match for validation).
		$message  = stripcslashes($message);
		# Step 0) - Find our cert.
		$fqdn     = gethostname();
		$tokens   = explode('.', $fqdn);
		$hostname = $tokens[0];
		$ssldir   = $config['ssldir'];
        // TODO: Update this certificate information
		$certname = $config['certnames']['fedoramagazine.org'];
		# Step 1) - Load and encode the X509 cert
		$cert_obj = openssl_x509_read(file_get_contents($ssldir . '/' . $certname . ".crt"));
		$cert     = "";
		openssl_x509_export($cert_obj, $cert);
		$cert        = base64_encode($cert);
		# Step 2) - Load and sign the jsonified message with the RSA private key
		$rsa_private = openssl_get_privatekey(file_get_contents($ssldir . '/' . $certname . ".key"));
		$signature   = "";
		openssl_sign($message, $signature, $rsa_private);
		$signature                  = base64_encode($signature);
		# Step 3) - Stuff it back in the message and return
		$message_obj['signature']   = $signature;
		$message_obj['certificate'] = $cert;
		return $message_obj;
	}
	protected function emit_message($subtopic, $message)
	{

		global $config, $queue;
		# Re-implement some of the logc from fedmsg/core.py
		# We'll have to be careful to keep this up to date.
		$prefix      = "org." . $config['environment'] . ".fedoramagazine.";
		$topic       = $prefix . $subtopic;
		$message_obj = array(
			"topic" => $topic,
			"msg" => $message,
			"timestamp" => round(time(), 3),
			"msg_id" => date("Y") . "-" . uuid_create(),
			"username" => "apache",
			# TODO -> we don't have a good way to increment this counter from php yet.
			"i" => 1
		);
		if (array_key_exists('sign_messages', $config) and to_bool($config['sign_messages']))
		{
			$message_obj = sign_message($message_obj);
		}
		$envelope = json_encode($message_obj);
		$queue->send($topic, ZMQ::MODE_SNDMORE);
		$queue->send($envelope);
	}
}
class fedmsg_handlers
{

	static function new_post($post_ID, $post)
	{
		if (!initialize()) { return false; } # Try to initialize socket

		$current_user = wp_get_current_user();
		$post_url     = get_permalink($post_ID);
		$msg          = array(
			"title" => $post->post_title,
			"author_user" => $post->post_author,
			"publisher_user" => $current_user->user_login
		);
		emit_message($topic, $msg);
		return true;
	}

	function process_stats()
	{
		if (!initialize()) { return false; } # Try to initialize socket
		// API Ref: http://stats.wordpress.com/csv.php
		// A WP API key is needed for this
		return;

	}
}

class fedmsg_plugin_meta
{
	static function add_sidebar_section()
	{
		if (is_admin()) // check if user is an admin
		{
			add_action('admin_menu', 'add_mymenu');
			add_action('admin_init', 'register_mysettings');
		}
		else
		{
			// don't show fedmsg settings
		}
	}
	function register_settings() // whitelist options
	{
		register_setting('fedmmsg-option-group', 'new_option_name');
		register_setting('fedmmsg-option-group', 'some_other_option');
		register_setting('fedmmsg-option-group', 'option_etc');
	}
}
# Dispatch an event to fedmsg each time a post is published
add_action('publish_post', array(
	"fedmsg_handlers",
	'new_post'
));
# Add fedmsg to admin sidebar
add_action('admin_init', array(
	"fedmsg_plugin_meta",
	'add_sidebar_section'
));
