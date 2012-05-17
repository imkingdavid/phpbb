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
* Handles avatars selected from the board gallery
* @package avatars
*/
class phpbb_avatar_driver_local extends phpbb_avatar_driver
{
	/**
	* @inheritdoc
	*/
	public function get_data($user_row, $ignore_config = false)
	{
		if ($ignore_config || $this->config['allow_avatar_local'])
		{
			return array(
				'src' => $this->phpbb_root_path . $this->config['avatar_gallery_path'] . '/' . $user_row['user_avatar'],
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
		$avatar_list = ($this->cache == null) ? false : $this->cache->get('av_local_list');

		if (!$avatar_list)
		{
			$avatar_list = array();
			$path = $this->phpbb_root_path . $this->config['avatar_gallery_path'];

			$dh = @opendir($path);

			if (!$dh)
			{
				return $avatar_list;
			}

			while (($cat = readdir($dh)) !== false) {
				if ($cat[0] != '.' && preg_match('#^[^&"\'<>]+$#i', $cat) && is_dir("$path/$cat"))
				{
					if ($ch = @opendir("$path/$cat"))
					{
						while (($image = readdir($ch)) !== false)
						{
							// Match all images in the gallery folder
							if (preg_match('#^[^&\'"<>]+\.(?:gif|png|jpe?g)$#i', $image))
							{
								if (function_exists('getimagesize'))
								{
									$dims = getimagesize($this->phpbb_root_path . $this->config['avatar_gallery_path'] . '/' . $cat . '/' . $image);
								}
								else
								{
									$dims = array(0, 0);
								}
								$avatar_list[$cat][$image] = array(
									'file'      => rawurlencode($cat) . '/' . rawurlencode($image),
									'filename'  => rawurlencode($image),
									'name'      => ucfirst(str_replace('_', ' ', preg_replace('#^(.*)\..*$#', '\1', $image))),
									'width'     => $dims[0],
									'height'    => $dims[1],
								);
							}
						}
						@closedir($ch);
					}
				}
			}
			@closedir($dh);

			@ksort($avatar_list);

			if ($this->cache != null)
			{
				$this->cache->put('av_local_list', $avatar_list);
			}
		}
		
		$category = request_var('av_local_cat', '');
		
		if ($submitted) {
			$file = request_var('av_local_file', '');
			if (!isset($avatar_list[$category][urldecode($file)]))
			{
				$error[] = 'AVATAR_URL_NOT_FOUND';
				return false;
			}

			return array(
				'user_avatar' => $category . '/' . $file,
				'user_avatar_width' => $avatar_list[$category][urldecode($file)]['width'],
				'user_avatar_height' => $avatar_list[$category][urldecode($file)]['height'],
			);
		}


		$categories = array_keys($avatar_list);

		foreach ($categories as $cat)
		{
			if (!empty($avatar_list[$cat]))
			{
				$template->assign_block_vars('av_local_cats', array(
					'NAME' => $cat,
					'SELECTED' => ($cat == $category),
				));
			}
		}

		if (!empty($avatar_list[$category]))
		{
			foreach ($avatar_list[$category] as $img => $data)
			{
				$template->assign_block_vars('av_local_imgs', array(
					'AVATAR_IMAGE'  => $path . '/' . $data['file'],
					'AVATAR_NAME' => $data['name'],
					'AVATAR_FILE' => $data['filename'],
				));
			}
		}

		return true;
	}
}
