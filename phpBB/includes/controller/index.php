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
* Controller for the front page of a phpBB forum
* @package phpBB3
*/
class phpbb_controller_index
{
	/**
	* Template object
	* @var phpbb_template
	*/
	protected $template;

	/**
	* User object
	* @var phpbb_user
	*/
	protected $user;

	/**
	* phpBB root path
	* @var string
	*/
	protected $phpbb_root_path;

	/**
	* PHP extension
	* @var string
	*/
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
	* @param phpbb_controller_helper
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

		$this->user->add_lang('viewforum');
		if (!function_exists('display_forums'))
		{
			include($phpbb_root_path . 'includes/functions_display.' . $php_ext);
		}
	}

	/**
	* Controller method responsible for displaying the board index page
	*
	* This controller method is accessed directly from the route: /
	*
	* @return Response
	*/
	public function main()
	{
		$this->mark_notifications_read();
		display_forums('', $this->config['load_moderators']);
		$legend = $this->generate_legend();
		$birthday_list = $this->generate_birthday_list();

		// Assign index specific vars
		$this->template->assign_vars(array(
			'TOTAL_POSTS'	=> $this->user->lang('TOTAL_POSTS_COUNT', (int) $this->config['num_posts']),
			'TOTAL_TOPICS'	=> $this->user->lang('TOTAL_TOPICS', (int) $this->config['num_topics']),
			'TOTAL_USERS'	=> $this->user->lang('TOTAL_USERS', (int) $this->config['num_users']),
			'NEWEST_USER'	=> $this->user->lang('NEWEST_USER', get_username_string('full', $this->config['newest_user_id'], $this->config['newest_username'], $this->config['newest_user_colour'])),

			'LEGEND'		=> $legend,
			'BIRTHDAY_LIST'	=> (empty($birthday_list)) ? '' : implode($this->user->lang['COMMA_SEPARATOR'], $birthday_list),

			'FORUM_IMG'				=> $this->user->img('forum_read', 'NO_UNREAD_POSTS'),
			'FORUM_UNREAD_IMG'			=> $this->user->img('forum_unread', 'UNREAD_POSTS'),
			'FORUM_LOCKED_IMG'		=> $this->user->img('forum_read_locked', 'NO_UNREAD_POSTS_LOCKED'),
			'FORUM_UNREAD_LOCKED_IMG'	=> $this->user->img('forum_unread_locked', 'UNREAD_POSTS_LOCKED'),

			'S_LOGIN_ACTION'			=> append_sid("{$this->phpbb_root_path}ucp.{$this->php_ext}", 'mode=login'),
			'S_DISPLAY_BIRTHDAY_LIST'	=> ($this->config['load_birthdays']) ? true : false,

			'U_MARK_FORUMS'		=> ($this->user->data['is_registered'] || $this->config['load_anon_lastread']) ? append_sid("{$this->phpbb_root_path}index.{$this->php_ext}", 'hash=' . generate_link_hash('global') . '&amp;mark=forums&amp;mark_time=' . time()) : '',
			'U_MCP'				=> ($this->auth->acl_get('m_') || $this->auth->acl_getf_global('m_')) ? append_sid("{$this->phpbb_root_path}mcp.{$this->php_ext}", 'i=main&amp;mode=front', true, $this->user->session_id) : '')
		);

		$page_title = $this->user->lang['INDEX'];

		/**
		* You can use this event to modify the page title and load data for the index
		*
		* @event core.index_modify_page_title
		* @var	string	page_title		Title of the index page
		* @since 3.1-A1
		*/
		$vars = array('page_title');
		extract($this->dispatcher->trigger_event('core.index_modify_page_title', compact($vars)));

		return $this->helper->render('index_body.html', $page_title);
	}

	/**
	* Take care of marking a notification read
	*
	* @return null
	*/
	public function mark_notification_read()
	{
		if (($mark_notification = $this->request->variable('mark_notification', 0)))
		{
			$notification = $this->notification_manager->load_notifications(array(
				'notification_id'	=> $mark_notification
			));

			if (isset($notification['notifications'][$mark_notification]))
			{
				$notification = $notification['notifications'][$mark_notification];

				$notification->mark_read();

				if (($redirect = $request->variable('redirect', '')))
				{
					redirect(append_sid($phpbb_root_path . $redirect));
				}

				redirect($notification->get_url());
			}
		}
	}

	/**
	* Generate the who's online list legend
	*
	* @return string A comma-separated, HTML-formatted list of groups
	*/
	public function generate_legend()
	{
		$order_legend = ($this->config['legend_sort_groupname']) ? 'group_name' : 'group_legend';
		// Grab group details for legend display
		if ($this->auth->acl_gets('a_group', 'a_groupadd', 'a_groupdel'))
		{
			$sql = 'SELECT group_id, group_name, group_colour, group_type, group_legend
				FROM ' . GROUPS_TABLE . '
				WHERE group_legend > 0
				ORDER BY ' . $order_legend . ' ASC';
		}
		else
		{
			$sql = 'SELECT g.group_id, g.group_name, g.group_colour, g.group_type, g.group_legend
				FROM ' . GROUPS_TABLE . ' g
				LEFT JOIN ' . USER_GROUP_TABLE . ' ug
					ON (
						g.group_id = ug.group_id
						AND ug.user_id = ' . $this->user->data['user_id'] . '
						AND ug.user_pending = 0
					)
				WHERE g.group_legend > 0
					AND (g.group_type <> ' . GROUP_HIDDEN . ' OR ug.user_id = ' . $this->user->data['user_id'] . ')
				ORDER BY g.' . $order_legend . ' ASC';
		}
		$result = $this->db->sql_query($sql);

		$legend = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$colour_text = ($row['group_colour']) ? ' style="color:#' . $row['group_colour'] . '"' : '';
			$group_name = ($row['group_type'] == GROUP_SPECIAL) ? $this->user->lang['G_' . $row['group_name']] : $row['group_name'];

			if ($row['group_name'] == 'BOTS' || ($this->user->data['user_id'] != ANONYMOUS && !$this->auth->acl_get('u_viewprofile')))
			{
				$legend[] = '<span' . $colour_text . '>' . $group_name . '</span>';
			}
			else
			{
				$legend[] = '<a' . $colour_text . ' href="' . append_sid("{$this->phpbb_root_path}memberlist.{$this->php_ext}", 'mode=group&amp;g=' . $row['group_id']) . '">' . $group_name . '</a>';
			}
		}
		$this->db->sql_freeresult($result);

		return implode($this->user->lang['COMMA_SEPARATOR'], $legend);
	}

	/**
	* Generate a list of users having a birthday today
	*
	* @return array
	*/
	public function generate_birthday_list()
	{
		// Generate birthday list if required ...
		$birthday_list = array();
		if ($this->config['load_birthdays'] && $this->config['allow_birthdays'] && $this->auth->acl_gets('u_viewprofile', 'a_user', 'a_useradd', 'a_userdel'))
		{
			$time = $this->user->create_datetime();
			$now = phpbb_gmgetdate($time->getTimestamp() + $time->getOffset());

			// Display birthdays of 29th february on 28th february in non-leap-years
			$leap_year_birthdays = '';
			if ($now['mday'] == 28 && $now['mon'] == 2 && !$time->format('L'))
			{
				$leap_year_birthdays = " OR u.user_birthday LIKE '" . $this->db->sql_escape(sprintf('%2d-%2d-', 29, 2)) . "%'";
			}

			$sql = 'SELECT u.user_id, u.username, u.user_colour, u.user_birthday
				FROM ' . USERS_TABLE . ' u
				LEFT JOIN ' . BANLIST_TABLE . " b ON (u.user_id = b.ban_userid)
				WHERE (b.ban_id IS NULL
					OR b.ban_exclude = 1)
					AND (u.user_birthday LIKE '" . $this->db->sql_escape(sprintf('%2d-%2d-', $now['mday'], $now['mon'])) . "%' $leap_year_birthdays)
					AND u.user_type IN (" . USER_NORMAL . ', ' . USER_FOUNDER . ')';
			$result = $this->db->sql_query($sql);

			while ($row = $this->db->sql_fetchrow($result))
			{
				$birthday_username	= get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']);
				$birthday_year		= (int) substr($row['user_birthday'], -4);
				$birthday_age		= ($birthday_year) ? max(0, $now['year'] - $birthday_year) : '';

				$this->template->assign_block_vars('birthdays', array(
					'USERNAME'	=> $birthday_username,
					'AGE'		=> $birthday_age,
				));

				// For 3.0 compatibility
				if ($age = (int) substr($row['user_birthday'], -4))
				{
					$birthday_list[] = $birthday_username . (($birthday_year) ? ' (' . $birthday_age . ')' : '');
				}
			}
			$this->db->sql_freeresult($result);
		}

		return $birthday_list;
	}
}
