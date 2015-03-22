<?php
// $HeadURL: https://joomgallery.org/svn/joomgallery/JG-3/Plugins/JoomContentPlu/trunk/joomplu.php $
// $Id: joomplu.php 4262 2013-05-06 22:15:27Z chraneco $
/******************************************************************************\
**   JoomGallery Content Plugin 'JoomPlu' 1.5                                 **
**   By: JoomGallery::ProjectTeam                                             **
**   Copyright (C) 2009 - 2011  Patrick Alt                                   **
**   Released under GNU GPL Public License                                    **
**   License: http://www.gnu.org/copyleft/gpl.html                            **
\******************************************************************************/

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die('Restricted access');

jimport('joomla.plugin.plugin');

/**
 * JoomGallery Content Plugin
 *
 * Shows images from JoomGallery in content articles
 *
 * @package     Joomla
 * @subpackage  Content
 * @since       1.5.0
 */
class plgContentJoomPlu extends JPlugin
{
  /**
   * Constructor
   *
   * For php4 compatability we must not use the __constructor as a constructor for plugins
   * because func_get_args ( void ) returns a copy of all passed arguments NOT references.
   * This causes problems with cross-referencing necessary for the observer design pattern.
   *
   * @param   object    $subject The object to observe
   * @param   object    $params  The object that holds the plugin parameters
   * @return  void
   * @since   1.5.0
   */
  public function plgContentJoomPlu(&$subject, $params)
  {
    parent::__construct($subject, $params);

    // Load the language file
    $this->loadLanguage('', JPATH_ADMINISTRATOR);
  }

  /**
   * JoomPlu prepare content method
   *
   * Method is called by the view
   *
   * @param   object  The article object. Note: $article->text is also available
   * @param   object  The article params
   * @param   int     The 'page' number
   * @return  void
   * @since   1.5.0
   */
  public function onContentPrepare($context, $article, $params, $limitstart = 0)
  {
    // Simple performance check to determine whether bot should process further
    if(strpos($article->text, 'joomplu') === false)
    {
      return;
    }

    // Check existence of JoomGallery and include the interface class
    if(!file_exists(JPATH_ROOT.'/components/com_joomgallery/interface.php'))
    {
      $output         = '<p><b>'.JText::_('PLG_JOOMPLU_JG_NOT_INSTALLED').'</b></p>';
      $article->text  = $output.$article->text;

      return;
    }
    require_once JPATH_ROOT.'/components/com_joomgallery/interface.php';

    // Create interface object
    $this->_interface = new JoomInterface();

    // Get user object
    $user = JFactory::getUser();

    // Include necessary style sheets if we are asked for it or if we have to
    if($this->params->get('use_gallery_styles') || strpos($article->text, 'joomplucat') !== false)
    {
      $this->_interface->getPageHeader();
    }

    // Set image opening method
    if($this->params->get('openimage', 'default') != 'default')
    {
      $default_openimage = $this->params->get('openimage', 6);
      $this->_interface->addConfig('openimage', $default_openimage);
    }

    // Set default column number for displaying categories
    $this->_interface->addConfig('default_columns', $this->params->get('default_columns'));

    // Do we want to display hidden images and categories also?
    $this->_interface->addConfig('showhidden', $this->params->get('showhidden'));

    // Set additional default settings
    $this->_setConfig($this->params->get('additional_settings'), false);

    // Store config in order to be able to reset it later on with the params set above
    $this->_interface->storeConfig();

    // Regular expressions
    $regex_img  = '/href="joomplu:([0-9]+)([a-z,A-Z,0-9,%,=,|, ]*)"/';
    $regex_alt  = '/alt="joomplu:([0-9]+)([a-z, ]*)"/';
    $regex_tag  = '/{joomplu:([0-9]+)(.*?)}/';
    $regex_cat  = '/{joomplucat:([0-9]+)(.*)}/';//([a-z,=,",|, ]*)
    $regex_link = '/href="joomplulink:([0-9]+)([a-z,A-Z,0-9,%,=,|, ]*)"/';

    // Main task 1 -> Search for image links
    if(preg_match_all($regex_img, $article->text, $matches, PREG_SET_ORDER))
    {
      // For debug
      //echo '<pre>';print_r($matches);echo '</pre>';

      foreach($matches as $match)
      {
        $this->_setConfig(str_replace(array('%20', '%7C'), array(' ', '|'), $match[2]));

        // Get image object
        $imgs[$match[1]] = $this->_interface->getPicture($match[1], $user->get('aid'));
        if(!is_null($imgs[$match[1]]))
        {
          if($this->_interface->getConfig('catlink'))
          {
            $output = 'href="'.$this->_interface->route('index.php?view=category&catid='.$imgs[$match[1]]->catid).'"';
          }
          else
          {
            $type = false;
            if($this->_interface->getConfig('type') == 'orig')
            {
              if(file_exists($this->_interface->getAmbit()->getImg('orig_path', $imgs[$match[1]])))
              {
                $type = 'orig';
              }
              else
              {
                $type = 'img';
              }
            }
            $output = 'href="'.$this->_interface->getImageLink($imgs[$match[1]], $type).'"';
          }
        }
        // Image not found
        else
        {
          $output = 'href="javascript:alert(\''.JText::_('PLG_JOOMPLU_IMAGE_NOT_DISPLAYABLE', true).'\');"';

          // Send system message if configured
          if($this->params->get('msg', 0))
          {
            $this->_sendMessages($match[1], $article->id, $article->title);
          }
        }

        $this->_interface->resetConfig();

        $article->text = str_replace($match[0], $output, $article->text);
      }
    }

    // Main task 2 -> Search for alt attributes
    if(preg_match_all($regex_alt, $article->text, $matches, PREG_SET_ORDER))
    {
      // For debug
      //echo '<pre>';print_r($matches);echo '</pre>';

      foreach($matches as $match)
      {
        $this->_setConfig($match[2]);

        $new = false;
        // Get image object
        if(!isset($imgs[$match[1]]))
        {
          $imgs[$match[1]] = $this->_interface->getPicture($match[1], $user->get('aid'));
          $new = true;
        }

        // Image found
        if(!is_null($imgs[$match[1]]))
        {
            $output = 'alt="'.$imgs[$match[1]]->imgtitle.'"';
        }
        // Image not found
        else
        {
          $output = 'alt="'.JText::_('PLG_JOOMPLU_IMAGE_NOT_DISPLAYABLE').'"';

          if($new)
          {
            // Send system message if configured
            if($this->params->get('msg', 0))
            {
              $this->_sendMessages($match[1], $article->id, $article->title);
            }
          }
        }

        $this->_interface->resetConfig();

        $article->text = str_replace($match[0], $output, $article->text);
      }
    }

    // Main task 3 -> Search for image tags
    // This is only still here for backward compatibility
    if(preg_match_all($regex_tag, $article->text, $matches, PREG_SET_ORDER))
    {
      // For debug
      //echo '<pre>';print_r($matches);echo '</pre>';

      foreach($matches as $match)
      {
        $this->_setConfig($match[2]);

        $new = false;
        // Get image object
        if(!isset($imgs[$match[1]]))
        {
          $imgs[$match[1]] = $this->_interface->getPicture($match[1], $user->get('aid'));
          $new = true;
        }

        // Image found
        if(!is_null($imgs[$match[1]]))
        {
          // Linked
          if(strpos($match[2], 'not linked') === false)
          {
            $linked = true;
          }
          else
          {
            $linked = false;
          }

          // Alignment
          if(strpos($match[2], 'right') === false)
          {
            if(strpos($match[2], 'left') === false)
            {
              $extra = null;
            }
            else
            {
              $extra = 'jg_floatleft';#$extra = 'align="left"';
            }
          }
          else
          {
            $extra = 'jg_floatright';#$extra = 'align="right"';
          }

          // Detail image or thumbnail
          if(   strpos($match[2], 'detail') === false
            &&  strpos($match[2], 'img') === false
            &&  strpos($match[2], 'orig') === false)
          {
            $output = $this->_interface->displayThumb($imgs[$match[1]], $linked, $extra);
          }
          else
          {
            $output = $this->_interface->displayDetail($imgs[$match[1]], $linked, $extra);
          }
        }
        // Image not found
        else
        {
          $output = ''; //'<p><b>'.JText::_('PLG_JOOMPLU_IMAGE_NOT_DISPLAYABLE').'</b></p>';

          // Send system message if configured
          if($new)
          {
            if($this->params->get('msg', 0))
            {
              $this->_sendMessages($match[1], $article->id, $article->title);
            }
          }
        }

        $this->_interface->resetConfig();

        $article->text = str_replace($match[0], $output, $article->text);
      }
    }

    // Main task 4 -> Search for category tags
    if(preg_match_all($regex_cat, $article->text, $matches, PREG_SET_ORDER))
    {
      // For debug
      //echo '<pre>';print_r($matches);echo '</pre>';

      $this->_interface->getPageHeader();

      foreach($matches as $match)
      {
        $this->_setConfig($match[2]);

        $ordering = $this->_interface->getConfig('ordering');
        switch($ordering)
        {
          case 'random':
            $ordering = 'RAND()';
            break;
          default:
            $ordering = 'jg.ordering';
            break;
        }

        $rows   = $this->_interface->getPicsByCategory($match[1], $user->get('aid'), $ordering, $this->_interface->getConfig('limit'));
        $output = $this->_interface->displayThumbs($rows);

        $this->_interface->resetConfig();

        $article->text = str_replace($match[0], $output, $article->text);
      }
    }

    // Main task 5 -> Search for links
    if(preg_match_all($regex_link, $article->text, $matches, PREG_SET_ORDER))
    {
      // For debug
      //echo '<pre>';print_r($matches);echo '</pre>';

      foreach($matches as $match)
      {
        $this->_setConfig(str_replace(array('%20', '%7C'), array(' ', '|'), $match[2]));

        $view   = $this->_interface->getConfig('view');
        switch($view)
        {
          case 'category':
            $query = '&catid='.$match[1];
            break;
          case 'detail':
            $query = '&id='.$match[1];
            break;
          default:
            $query = '';
            break;
        }

        $output = 'href="'.$this->_interface->route('index.php?view='.$view.$query).'"';

        $this->_interface->resetConfig();

        $article->text = str_replace($match[0], $output, $article->text);
      }
    }

    // Route image source links
		$regex = '#src="index.php\?([^"]*)option='._JOOM_OPTION.'([^"]*)#m';
		$article->text = preg_replace_callback($regex, array($this, 'route'), $article->text);
  }

  /**
   * Sets all given configuration settings in the interface
   *
   * @param   string    The string containing the settings
   * @param   boolean   Determines whether the string has to be transformed into an ini string first
   * @return  void
   * @since   1.5.0
   */
  protected function _setConfig($config_string, $build_ini = true)
  {
    if($build_ini)
    {
      $ini_string = str_replace('|', "\n", $config_string);
    }
    else
    {
      $ini_string = $config_string;
    }

    $params = new JRegistry();
    $params->loadString($ini_string);

    $params_array = $params->toArray();

    foreach($params_array as $key => $value)
    {
      $this->_interface->addConfig(trim($key), trim($value));
    }
  }

  /**
   * JoomPlu message sending method
   *
   * Method sends system messages to configured users
   * is called when an invalid image id was found in the article
   *
   * @param   int       The image id
   * @param   int       The article id
   * @param   string    The article title
   * @return  void
   * @since   1.5.0
   */
  protected function _sendMessages($pic_id, $article_id, $article_title)
  {
    $db = JFactory::getDbo();

    // Register messages classes
    JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR.'/components/com_messages/models', 'MessagesModel');
    JTable::addIncludePath(JPATH_ADMINISTRATOR.'/components/com_messages/tables');

    // Create messages object
    $msg = JModelLegacy::getInstance('Message', 'MessagesModel');

    // Find users who want to receive messages
    if(!$this->params->get('msg_to', 0))
    {
      $query = $db->getQuery(true)
            ->select('id')
            ->from($db->qn('#__users'))
            ->where('sendEmail = 1');
      $db->setQuery($query);
      $user_ids = $db->loadColumn();
    }
    else
    {
      $user_ids = explode(',',$this->params->get('msg_to', 0));
    }

    foreach($user_ids as $user_id)
    {
      $user_to = JFactory::getUser($user_id);

      // Ensure that a valid user was selected
      if(is_object($user_to))
      {
        $message = JText::sprintf('PLG_JOOMPLU_MESSAGE_TEXT', $pic_id, $article_id, $article_title);

        // Check whether message was already sent
        $query = $db->getQuery(true)
              ->select('message')
              ->from($db->qn('#__messages'))
              ->where('user_id_to = '.$user_id);
        $db->setQuery($query);
        $messages = $db->loadColumn();
        $sent = false;
        foreach($messages as $sent_message)
        {
          if($sent_message == $message)
          {
            $sent = true;
            // Breaks foreach because we have what we wanted
            break;
          }
        }
        if($sent)
        {
          // Next loop
          continue;
        }

        // Send message
        $message_array = array();
        $message_array['user_id_to']    = $user_id;
        $message_array['user_id_from']  = $user_id;
        $message_array['subject']       = JText::_('PLG_JOOMPLU_MESSAGE_SUBJECT');
        $message_array['message']       = $message;
        $msg->save($message_array);
      }
    }
  }

  /**
   * Replaces the matched links with routed ones
   *
   * @param   array   &$matches An array of matches (see preg_match_all)
   * @return  string
   * @since   3.0
   */
  protected function route(&$matches)
  {
    $url   = $matches[1].'option='._JOOM_OPTION.$matches[2].$this->_interface->getAmbit()->getItemid();
    $url   = str_replace('&amp;', '&', $url);
    $route = JRoute::_('index.php?'.$url);

    return 'src="'.$route;
  }
}