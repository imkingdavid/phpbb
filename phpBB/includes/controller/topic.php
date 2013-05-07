<?php
/**
*
* @package controller
* @copyright (c) 2013 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

use Symfony\Component\HttpFoundation\Response;

/**
* Controller for the topic view page
* @package phpBB3
*/
class phpbb_controller_topic
{
	/** @var phpbb_user	*/
	protected $user;

	/** @var phpbb_request */
	protected $request;

	/** @var phpbb_template	*/
	protected $template;

	/** @var phpbb_notification_manager */
	protected $notification_manager;

	/** @var phpbb_config */
	protected $config;

	/** @var phpbb_auth */
	protected $auth;

	/** @var phpbb_db_driver */
	protected $db;

	/** @var phpbb_event_dispatcher $dispatcher */
	protected $dispatcher;

	/** @var phpbb_controller_helper $helper */
	protected $helper;

	/** @var string	*/
	protected $phpbb_root_path;

	/** @var string	*/
	protected $php_ext;

	/**
	* Constructor
	*
	* @param phpbb_user $user
	* @param phpbb_request $request
	* @param phpbb_template $template
	* @param phpbb_notification_manager $notification_manager
	* @param phpbb_config $config
	* @param phpbb_auth $auth
	* @param phpbb_db_driver $db
	* @param phpbb_event_dispatcher $dispatcher
	* @param phpbb_controller_helper $helper
	* @param string $phpbb_root_path
	* @param string $php_ext
	*/
	public function __construct(phpbb_user $user, phpbb_request $request, phpbb_template $template, phpbb_notification_manager $notification_manager, phpbb_config $config, phpbb_auth $auth, phpbb_db_driver $db, phpbb_event_dispatcher $dispatcher, phpbb_controller_helper $helper, $phpbb_root_path, $php_ext)
	{
		$this->user = $user;
		$this->request = $request;
		$this->template = $template;
		$this->notification_manager = $notification_manager;
		$this->config = $config;
		$this->db = $db;
		$this->auth = $auth;
		$this->dispatcher = $dispatcher;
		$this->helper = $helper;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;

		$this->user->add_lang('viewtopic');

		if (!function_exists('display_forums'))
		{
			include($phpbb_root_path . 'includes/functions_display.' . $php_ext);
		}

		if (!class_exists('bbcode'))
		{
			include($phpbb_root_path . 'includes/bbcode.' . $phpEx);
		}
	}

	/**
	* Controller method responsible for displaying a topic's posts
	*
	* This controller method is accessed directly from the routes:
	* /topic/{topic_id}
	* /topic/{topic_id}/print
	*
	* @return Response
	*/
	public function main($topic_id, $view = '')
	{
		// If the topic_id in the URL is in the format of ##-abc-123
		// remove the portion including and following the first dash
		// The ## is the topic ID and the rest is for user-friendliness
		if (strpos('-', $topic_id))
		{
			list($note_id, $slug) = $this->helper->separate_slug($topic_id);
		}
		else
		{
			$topic_id = (int) $topic_id;
			$slug = '';
		}
		$this->topic_id = $topic_id;
		if (!$this->topic_id)
		{
			$this->helper->error($this->user->lang('NO_TOPIC'), 404);
		}
		// Based on the route definition, $view will be one of:
		// print, unread, next, prev, or an empty string
		$this->view = $view;
		$this->set_up_initial_vars();

		// Find topic id if user requested a newer or older topic
		if ($this->view && !$this->post_id)
		{
			if (!$this->forum_id)
			{
				$sql = 'SELECT forum_id
					FROM ' . TOPICS_TABLE . "
					WHERE topic_id = $topic_id";
				$result = $this->db->sql_query($sql);
				$this->forum_id = (int) $this->db->sql_fetchfield('forum_id');
				$this->db->sql_freeresult($result);

				if (!$this->forum_id)
				{
					return $this->helper->error($this->user->lang('NO_TOPIC'), 404);
				}
			}

			if ($view == 'unread')
			{
				// Get topic tracking info
				$topic_tracking_info = get_complete_topic_tracking($this->forum_id, $this->topic_id);

				$topic_last_read = isset($topic_tracking_info[$this->topic_id]) ? $topic_tracking_info[$this->topic_id] : 0;

				$sql = 'SELECT post_id, topic_id, forum_id
					FROM ' . POSTS_TABLE . "
					WHERE topic_id = {$this->topic_id}
						" . (($this->auth->acl_get('m_approve', $this->forum_id)) ? '' : 'AND post_approved = 1') . "
						AND post_time > $topic_last_read
						AND forum_id = {$this->forum_id}
					ORDER BY post_time ASC";
				$result = $this->db->sql_query_limit($sql, 1);
				$row = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);

				if (!$row)
				{
					$sql = 'SELECT topic_last_post_id as post_id, topic_id, forum_id
						FROM ' . TOPICS_TABLE . '
						WHERE topic_id = ' . $this->topic_id;
					$result = $this->db->sql_query($sql);
					$row = $this->db->sql_fetchrow($result);
					$this->db->sql_freeresult($result);
				}

				if (!$row)
				{
					return $this->helper->error($this->user->lang('NO_TOPIC'), 404);
				}

				$this->post_id = $row['post_id'];
				$this->topic_id = $row['topic_id'];
			}
			else if ($this->view == 'next' || $this->view == 'previous')
			{
				$sql_condition = ($this->view == 'next') ? '>' : '<';
				$sql_ordering = ($this->view == 'next') ? 'ASC' : 'DESC';

				$sql = 'SELECT forum_id, topic_last_post_time
					FROM ' . TOPICS_TABLE . '
					WHERE topic_id = ' . $this->topic_id;
				$result = $this->db->sql_query($sql);
				$row = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);

				if (!$row)
				{
					// OK, the topic doesn't exist. This error message is not helpful, but technically correct.
					return $this->helper->error($this->user->lang($this->view == 'next' ? 'NO_NEWER_TOPICS' : 'NO_OLDER_TOPICS'), 404);
				}
				else
				{
					$sql = 'SELECT topic_id, forum_id
						FROM ' . TOPICS_TABLE . '
						WHERE forum_id = ' . $row['forum_id'] . "
							AND topic_moved_id = 0
							AND topic_last_post_time $sql_condition {$row['topic_last_post_time']}
							" . (($this->auth->acl_get('m_approve', $row['forum_id'])) ? '' : 'AND topic_approved = 1') . "
						ORDER BY topic_last_post_time $sql_ordering";
					$result = $this->db->sql_query_limit($sql, 1);
					$row = $this->db->sql_fetchrow($result);
					$this->db->sql_freeresult($result);

					if (!$row)
					{
						return $this->helper->error($this->user->lang($this->view == 'next' ? 'NO_NEWER_TOPICS' : 'NO_OLDER_TOPICS'), 404);
					}
					else
					{
						$this->topic_id = $row['topic_id'];
						$this->forum_id = $row['forum_id'];
					}
				}
			}

			if (isset($row) && $row['forum_id'])
			{
				$this->forum_id = $row['forum_id'];
			}
		}

		$topic_data = $this->get_topic_data();

		// If we still do not have topic data, we were linked to an unapproved
		// post or were given an incorrect link
		if (!$topic_data)
		{
			// If post_id was submitted, we try at least to display the topic as a last resort...
			if ($this->post_id && $this->topic_id)
			{
				redirect($this->helper->url('topic/' . $topic_id));
			}

			return $this->helper->error($this->user->lang('NO_TOPIC'), 404);
		}

		// Make sure we're using the correct forum ID
		$this->forum_id = $topic_data['forum_id'];

		// This is for determining where we are (page)
		if ($this->post_id)
		{
			// are we where we are supposed to be?
			if (!$topic_data['post_approved'] && !$this->auth->acl_get('m_approve', $topic_data['forum_id']))
			{
				// If post_id was submitted, we try at least to display the topic as a last resort...
				if ($this->topic_id)
				{
					redirect($this->helper->url('topic/' . $topic_id));
				}

				return $this->helper->error($this->user->lang('NO_TOPIC'), 404);
			}
			if ($this->post_id == $topic_data['topic_first_post_id'] || $this->post_id == $topic_data['topic_last_post_id'])
			{
				$check_sort = ($this->post_id == $topic_data['topic_first_post_id']) ? 'd' : 'a';

				if ($this->sort_dir == $check_sort)
				{
					$topic_data['prev_posts'] = ($this->auth->acl_get('m_approve', $forum_id)) ? $topic_data['topic_replies_real'] : $topic_data['topic_replies'];
				}
				else
				{
					$topic_data['prev_posts'] = 0;
				}
			}
			else
			{
				$sql = 'SELECT COUNT(p.post_id) AS prev_posts
					FROM ' . POSTS_TABLE . " p
					WHERE p.topic_id = {$topic_data['topic_id']}
						" . ((!$auth->acl_get('m_approve', $forum_id)) ? 'AND p.post_approved = 1' : '');

				if ($sort_dir == 'd')
				{
					$sql .= " AND (p.post_time > {$topic_data['post_time']} OR (p.post_time = {$topic_data['post_time']} AND p.post_id >= {$topic_data['post_id']}))";
				}
				else
				{
					$sql .= " AND (p.post_time < {$topic_data['post_time']} OR (p.post_time = {$topic_data['post_time']} AND p.post_id <= {$topic_data['post_id']}))";
				}

				$result = $this->db->sql_query($sql);
				$row = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);

				$topic_data['prev_posts'] = $row['prev_posts'] - 1;
			}
		}

		$this->topic_id = (int) $topic_data['topic_id'];
		//
		$topic_replies = ($this->auth->acl_get('m_approve', $this->forum_id)) ? $topic_data['topic_replies_real'] : $topic_data['topic_replies'];

		// Check sticky/announcement time limit
		if (($topic_data['topic_type'] == POST_STICKY || $topic_data['topic_type'] == POST_ANNOUNCE) && $topic_data['topic_time_limit'] && ($topic_data['topic_time'] + $topic_data['topic_time_limit']) < time())
		{
			$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET topic_type = ' . POST_NORMAL . ', topic_time_limit = 0
				WHERE topic_id = ' . $this->topic_id;
			$this->db->sql_query($sql);

			$topic_data['topic_type'] = POST_NORMAL;
			$topic_data['topic_time_limit'] = 0;
		}

		// Setup look and feel
		$this->user->set_style($topic_data['forum_style']);

		if (!$topic_data['topic_approved'] && !$this->auth->acl_get('m_approve', $this->forum_id))
		{
			return $this->helper->error($this->user->lang('NO_TOPIC'), 404);
		}

		// Start auth check
		if (!$this->auth->acl_get('f_read', $this->forum_id))
		{
			if ($this->user->data['user_id'] != ANONYMOUS)
			{
				$this->helper->error($this->user->lang('SORRY_AUTH_READ'), 403);
			}

			// @todo make login_box*() work with controllers
			// most likely requires refactoring most of the code into a new
			// function and creating a new helper method so that this can
			// continue to work outside of controllers
			login_box('', $this->user->lang['LOGIN_VIEWFORUM']);
		}

		// Forum is passworded ... check whether access has been granted to this
		// user this session, if not show login box
		if ($topic_data['forum_password'])
		{
			login_forum_box($topic_data);
		}

		return $this->helper->render($print ? 'viewtopic_body.html' : 'viewtopic_print.html', $page_title);
	}

	/**
	* Get the initial var information from the query string
	*
	* Some items that were in this list in viewtopic.php have been put into
	* the route and are taken from there instead of the query string.
	*
	* @return null
	*/
	protected function set_up_initial_vars()
	{
		$this->forum_id	= $this->request->variable('f', 0);
		$this->post_id	= $this->request->variable('p', 0);
		$this->voted_id	= $this->request->variable('vote_id', array('' => 0));

		$this->voted_id = (sizeof($voted_id) > 1) ? array_unique($voted_id) : $voted_id;

		$this->start		= $this->request->variable('start', 0);

		$default_sort_days	= $this->user->data['user_post_show_days'] ?: 0;
		$default_sort_key	= $this->user->data['user_post_sortby_type'] ?: 't';
		$default_sort_dir	= $this->user->data['user_post_sortby_dir'] ?: 'a';

		$this->sort_days	= $this->request->variable('st', $default_sort_days);
		$this->sort_key		= $this->request->variable('sk', $default_sort_key);
		$this->sort_dir		= $this->request->variable('sd', $default_sort_dir);

		$this->update		= $this->request->variable('update', false);

		$this->s_can_vote	= false;
		/**
		* @todo normalize?
		*/
		$this->hilit_words	= $this->request->variable('hilit', '', true);
	}

	public function get_topic_data()
	{
		// This rather complex gaggle of code handles querying for topics but
		// also allows for direct linking to a post (and the calculation of which
		// page the post is on and the correct display of viewtopic)
		$sql_array = array(
			'SELECT'	=> 't.*, f.*',

			'FROM'		=> array(FORUMS_TABLE => 'f'),
		);

		// The FROM-Order is quite important here, else t.* columns can not be correctly bound.
		if ($this->post_id)
		{
			$sql_array['SELECT'] .= ', p.post_approved, p.post_time, p.post_id';
			$sql_array['FROM'][POSTS_TABLE] = 'p';
		}

		// Topics table need to be the last in the chain
		$sql_array['FROM'][TOPICS_TABLE] = 't';

		if ($this->user->data['is_registered'])
		{
			$sql_array['SELECT'] .= ', tw.notify_status';
			$sql_array['LEFT_JOIN'] = array();

			$sql_array['LEFT_JOIN'][] = array(
				'FROM'	=> array(TOPICS_WATCH_TABLE => 'tw'),
				'ON'	=> 'tw.user_id = ' . $this->user->data['user_id'] . ' AND t.topic_id = tw.topic_id'
			);

			if ($this->config['allow_bookmarks'])
			{
				$sql_array['SELECT'] .= ', bm.topic_id as bookmarked';
				$sql_array['LEFT_JOIN'][] = array(
					'FROM'	=> array(BOOKMARKS_TABLE => 'bm'),
					'ON'	=> 'bm.user_id = ' . $this->user->data['user_id'] . ' AND t.topic_id = bm.topic_id'
				);
			}

			if ($this->config['load_db_lastread'])
			{
				$sql_array['SELECT'] .= ', tt.mark_time, ft.mark_time as forum_mark_time';

				$sql_array['LEFT_JOIN'][] = array(
					'FROM'	=> array(TOPICS_TRACK_TABLE => 'tt'),
					'ON'	=> 'tt.user_id = ' . $this->user->data['user_id'] . ' AND t.topic_id = tt.topic_id'
				);

				$sql_array['LEFT_JOIN'][] = array(
					'FROM'	=> array(FORUMS_TRACK_TABLE => 'ft'),
					'ON'	=> 'ft.user_id = ' . $this->user->data['user_id'] . ' AND t.forum_id = ft.forum_id'
				);
			}
		}

		if (!$this->post_id)
		{
			$sql_array['WHERE'] = "t.topic_id = {$this->topic_id}";
		}
		else
		{
			$sql_array['WHERE'] = "p.post_id = {$this->post_id} AND t.topic_id = p.topic_id";
		}

		$sql_array['WHERE'] .= ' AND f.forum_id = t.forum_id';

		$sql = $this->db->sql_build_query('SELECT', $sql_array);
		$result = $this->db->sql_query($sql);
		$topic_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $topic_data;
	}
}
