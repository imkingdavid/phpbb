<?php

class phpbb_last_post_info
{

	/**
	* @var Database object
	*/
	public static $db = null;

	/**
	* Retrieve the last post information for a topic or forum
	*
	* @param string $for Either 'topic' or 'forum', default is 'topic'
	* @param array|int $id ID(s) of topics/forums to get last topic data for
	* @return array|bool Depending on result, return array of data or false
	* @access public
	*/
	public static function get($type = 'topic', $ids = array(0))
	{
		if (empty($ids))
		{
			return false;
		}
		$ids = !is_array($ids) ? array($ids) : $ids;
		$type = ($type == 'topic') ? $type : 'forum';
		$last_post_ids = $update_sql = array();
		$type_id = "{$type}_id";

		$sql = "SELECT p.$type_id, MAX(p.post_id) as last_post_id, MAX(pr.post_id) as last_post_id_real
			FROM " . POSTS_TABLE . ' p, ' . POSTS_TABLE . ' pr
			WHERE ' . self::$db->sql_in_set("p.$type_id", $ids) . '
				AND ' . self::$db->sql_in_set("pr.$type_id", $ids) . '
				AND p.post_approved = 1';
		$result = self::$db->sql_query($sql);

		while ($row = self::$db->sql_fetchrow($result))
		{
			$last_post_ids['approved'][] = $row['last_post_id'];
			$last_post_ids['real'][] = $row['last_post_id_real'];
		}
		self::$db->sql_freeresult($result);

		if(empty($last_post_ids['real']))
		{
			return false;
		}

		$all_ids = array_unique(array_merge($last_post_ids['approved'], $last_post_ids['real']));
		$sql = 'SELECT p.' . $type_id . ', p.post_id, p.post_subject, p.post_time, p.poster_id, p.post_username, p.poster_colour, 
				u.user_id, u.username, u.user_colour
			FROM ' . POSTS_TABLE . ' p, ' . USERS_TABLE . ' u
			WHERE ' . self::$db->sql_in_set('p.post_id', $all_ids) . '
				AND p.poster_id = u.user_id';
		$result = self::$db->sql_query($sql);

		while ($row = $db->sql_fetchrow($result))
		{
			$username = ($row['poster_id'] == ANONYMOUS) ? $row['post_username'] : $row['username'];
			// Always update the *_real data, but only update the other if the post is approved
			if(in_array($row['p.post_id'], $last_post_ids['approved']))
			{
				$update_sql[$row[$type_id]][] = $type . '_last_post_id = ' . (int) $row['post_id'];
				$update_sql[$row[$type_id]][] = "{$type}_last_post_subject = '" . self::$db->sql_escape($row['post_subject']) . "'";
				$update_sql[$row[$type_id]][] = $type . '_last_post_time = ' . (int) $row['post_time'];
				$update_sql[$row[$type_id]][] = $type . '_last_poster_id = ' . (int) $row['poster_id'];
				$update_sql[$row[$type_id]][] = "{$type}_last_poster_colour = '" . self::$db->sql_escape($row['user_colour']) . "'";
				$update_sql[$row[$type_id]][] = "{$type}_last_poster_name = '" . self::$db->sql_escape($username) . "'";
			}
			$update_sql[$row[$type_id]][] = $type . '_last_post_id_real = ' . (int) $row['post_id'];
			$update_sql[$row[$type_id]][] = "{$type}_last_post_subject_real = '" . self::$db->sql_escape($row['post_subject']) . "'";
			$update_sql[$row[$type_id]][] = $type . '_last_post_time_real = ' . (int) $row['post_time'];
			$update_sql[$row[$type_id]][] = $type . '_last_poster_id_real = ' . (int) $row['poster_id'];
			$update_sql[$row[$type_id]][] = "{$type}_last_poster_colour_real = '" . self::$db->sql_escape($row['user_colour']) . "'";
			$update_sql[$row[$type_id]][] = "{$type}_last_poster_name_real = '" . self::$db->sql_escape($username) . "'";
		}
		self::$db->sql_freeresult($result);

		return !empty($update_sql) ? $update_sql : false;
	}

	/**
	* Update the topic or forum's last post data
	*
	* @param string $type What to update (topic|forum)
	* @param array|int $ids Array of IDs (of $type) to set.
	* @param array|null $input If not null, should be an array formatted like the return of self::get()
	* @return bool|null False on problem, otherwise null
	*/
	public static function set($type = 'topic', $ids = array(0), $input = null)
	{
		if (empty($input) && !($input = self::get($type, $ids)))
		{
			return false;
		}

		$table = ($type == 'topic') ? TOPICS_TABLE : FORUMS_TABLE;
		foreach ($input as $type_id => $sql_ary)
		{
			$sql = "UPDATE $table
				SET " . implode(', ', $sql_ary) . "
				WHERE {$type}_id = $type_id";
			self::$db->sql_query($sql);
		}

		return;
	}
}
