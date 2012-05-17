<?php
/**
*
* @package avatar
* @copyright (c) 2011 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* Handles avatars hosted remotely
* @package avatars
*/
class phpbb_avatar_driver_remote extends phpbb_avatar_driver
{
	/**
	* @inheritdoc
	*/
	public function get_data($user_row, $ignore_config = false)
	{
		if ($ignore_config || $this->config['allow_avatar_remote'])
		{
			return array(
				'src' => $user_row['user_avatar'],
				'width' => $user_row['user_avatar_width'],
				'height' => $user_row['user_avatar_height'],
			);
		}
		else
		{
			return array(
				'src' => '',
				'width' => 0,
				'height' => 0,
			);
		}
	}

	/**
	* @inheritdoc
	*/
	public function handle_form($template, $user_row, &$error, $submitted = false)
	{
		if ($submitted)
		{
			$url = request_var('av_remote_url', '');
			$width = request_var('av_remote_width', 0);
			$height = request_var('av_remote_height', 0);
			
			if (!preg_match('#^(http|https|ftp)://#i', $url))
			{
				$url = 'http://' . $url;
			}

			$error = array_merge($error, validate_data(array(
				'url' => $url,
			), array(
				'url' => array('string', true, 5, 255),
			)));

			if (!empty($error))
			{
				return false;
			}

			// Check if this url looks alright
			// This isn't perfect, but it's what phpBB 3.0 did, and might as well make sure everything is compatible
			if (!preg_match('#^(http|https|ftp)://(?:(.*?\.)*?[a-z0-9\-]+?\.[a-z]{2,4}|(?:\d{1,3}\.){3,5}\d{1,3}):?([0-9]*?).*?\.(gif|jpg|jpeg|png)$#i', $url))
			{
				$error[] = 'AVATAR_URL_INVALID';
				return false;
			}

			// Make sure getimagesize works...
			if (($image_data = getimagesize($url)) === false && ($width <= 0 || $height <= 0))
			{
				$error[] = 'UNABLE_GET_IMAGE_SIZE';
				return false;
			}

			if (!empty($image_data) && ($image_data[0] < 2 || $image_data[1] < 2))
			{
				$error[] = 'AVATAR_NO_SIZE';
				return false;
			}

			$width = ($width && $height) ? $width : $image_data[0];
			$height = ($width && $height) ? $height : $image_data[1];

			if ($width < 2 || $height < 2)
			{
				$error[] = 'AVATAR_NO_SIZE';
				return false;
			}

			include_once($this->phpbb_root_path . 'includes/functions_upload.' . $this->phpEx);
			$types = fileupload::image_types();
			$extension = strtolower(filespec::get_extension($url));

			if (!empty($image_data) && (!isset($types[$image_data[2]]) || !in_array($extension, $types[$image_data[2]])))
			{
				if (!isset($types[$image_data[2]]))
				{
					$error[] = 'UNABLE_GET_IMAGE_SIZE';
				}
				else
				{
					$error[] = array('IMAGE_FILETYPE_MISMATCH', $types[$image_data[2]][0], $extension);
				}

				return false;
			}

			if ($this->config['avatar_max_width'] || $this->config['avatar_max_height'])
			{
				if ($width > $this->config['avatar_max_width'] || $height > $this->config['avatar_max_height'])
				{
					$error[] = array('AVATAR_WRONG_SIZE', $this->config['avatar_min_width'], $this->config['avatar_min_height'], $this->config['avatar_max_width'], $this->config['avatar_max_height'], $width, $height);
					return false;
				}
			}

			if ($this->config['avatar_min_width'] || $this->config['avatar_min_height'])
			{
				if ($width < $this->config['avatar_min_width'] || $height < $this->config['avatar_min_height'])
				{
					$error[] = array('AVATAR_WRONG_SIZE', $this->config['avatar_min_width'], $this->config['avatar_min_height'], $this->config['avatar_max_width'], $this->config['avatar_max_height'], $width, $height);
					return false;
				}
			}

			$result = array(
				'user_avatar' => $url,
				'user_avatar_width' => $width,
				'user_avatar_height' => $height,
			);

			return $result;
		}
		else
		{
			$template->assign_vars(array(
				'AV_REMOTE_WIDTH' => (($user_row['user_avatar_type'] == AVATAR_REMOTE || $user_row['user_avatar_type'] == 'remote') && $user_row['user_avatar_width']) ? $user_row['user_avatar_width'] : request_var('av_local_width', 0),
				'AV_REMOTE_HEIGHT' => (($user_row['user_avatar_type'] == AVATAR_REMOTE || $user_row['user_avatar_type'] == 'remote') && $user_row['user_avatar_height']) ? $user_row['user_avatar_height'] : request_var('av_local_width', 0),
				'AV_REMOTE_URL' => (($user_row['user_avatar_type'] == AVATAR_REMOTE || $user_row['user_avatar_type'] == 'remote') && $user_row['user_avatar']) ? $user_row['user_avatar'] : '',
			));
			return true;
		}
	}
}
