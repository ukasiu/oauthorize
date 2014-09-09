<?php

/**
*
* @package MOD oauthorize
* @version $Id
* @copyright (c) 2007 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);
include($phpbb_root_path . 'oauthorize/functions_oauthorize.' . $phpEx);

// Put your own parameters here

$providers = array(

	'internal' => array(
		'key'    => 'wwwwwwwwwwwwwwww',
		'secret' => 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzz',		
	),
);

// Nothing needed to be modified after this point

// Start session management
$user->session_begin();
$auth->acl($user->data);
$user->setup();

$provider = request_var('provider','internal'); // 
$action = request_var('action','login'); //lets default to login
$user->add_lang('mods/oauthorize');

if (!empty($user->data['session_oauth']))
{
  $session_oauth = json_decode($user->data['session_oauth'], true);
}
else
// initialize the session_oauth if it does not exist
{
  $session_oauth = array (
                            'internal' => array(),  
                  );
}

// The first part is to record / connect to provider and get user login & token

switch ($provider) 
{
  // Internal authentication is several steps
  // Get temporary token using Internal application credentials
  // Then reload the page catching an oauth_verifier parameter from Internal
  // Get permament token using temporary token
  // Then reload the page 
  
  case 'internal':

    require './oauthorize/internal/internaloauth.php';
    
    $oauth_verifier = request_var('oauth_verifier',''); // returned by internal
        
    if (empty($oauth_verifier) && empty($session_oauth['internal']))
    {
      $internal = new InternalOAuth($providers['internal']['key'], $providers['internal']['secret']);
    
      $request_token = $internal->getRequestToken(get_current_url());
    
      if($internal->http_code == 200)
      {  
        // Let's generate the URL and redirect  
        $tw_url = $internal->getAuthorizeURL($request_token['oauth_token']);
      
        $session_oauth['internal'] = array(
          'tmp_token' => $request_token['oauth_token'],
          'tmp_secret' => $request_token['oauth_token_secret'],
        );

        record_session_oauth($session_oauth);   
        redirect($tw_url, false, true); 
      } 
      else 
      {   
        $message = $user->lang['OAUTH_MSG_ISSUE'];
        meta_refresh(3, $phpbb_root_path);
        trigger_error($message);  
      }
    }
    elseif(!empty($session_oauth['internal']['access_token']) && !empty($session_oauth['internal']['access_secret']))
    // We suppose, that we have permanent token
    {
      $internal = new InternalOAuth($providers['internal']['key'], $providers['internal']['secret'], $session_oauth['internal']['access_token'], $session_oauth['internal']['access_secret']);
       $internal_profile = $internal->get('appapi/students/me');
       
       if (empty($internal_profile['errors']))
       {    
          $oauth_profile = array(
            'id' => $internal_profile['id'],
          );
        }
        else
        // An error occurs: We reset all and restart the authentication process
        {          
          $session_oauth['internal'] = array();
          record_session_oauth($session_oauth);
          $redirect_url = $phpbb_root_path.'oauthorize.php?provider='.$provider.'&action=' . $action;
          redirect($redirect_url, false, true);
          exit();
        }
      }
      elseif(!empty($session_oauth['internal']['tmp_token']) && !empty($session_oauth['internal']['tmp_secret']))
      // We have temporary token
      // We request the permanent ones
      {
          $internal = new InternalOAuth($providers['internal']['key'], $providers['internal']['secret'], $session_oauth['internal']['tmp_token'], $session_oauth['internal']['tmp_secret']);
          $internal_profile = $internal->getAccessToken();
                    
          if (empty($internal_profile['errors']))
          {    
            $session_oauth['internal'] = array(
              'access_token' => $internal_profile['oauth_token'],
              'access_secret' => $internal_profile['oauth_token_secret'],  
              );
            record_session_oauth($session_oauth);
            $redirect_url = $phpbb_root_path.'oauthorize.php?provider='.$provider.'&action=' . $action;
            redirect($redirect_url, false, true);
            exit();
          }
          else
          // An error occurs: We do not reset the token to request new ones
          // We stop here to avoid possible infinite loop
          {
            $session_oauth['internal'] = array();
            record_session_oauth($session_oauth);
            $message = $user->lang['OAUTH_MSG_ISSUE'];
            meta_refresh(3, $phpbb_root_path);
            trigger_error($message);
          }               
      }
      else
      // We reset everything and restart the whole authentication process
      {
        $session_oauth['internal'] = array();
        record_session_oauth($session_oauth);
        $redirect_url = $phpbb_root_path.'oauthorize.php?provider='.$provider.'&action=' . $action;
        redirect($redirect_url, false, true);
      } 
  break;

  default:
    $message = $user->lang['OAUTH_UNKOWN_PROVIDER'];
    meta_refresh(3, $phpbb_root_path);    
    trigger_error($message);
    
  break;

} 

$oauth_column = 'pf_oauth_'.$provider.'_id';

switch ($action) {

  case 'login': 
    
    $config['auth_method'] = 'oauth'; // attempt oauth

    $auth->login($oauth_profile['id'], $provider);

    if ($user->data['is_registered']) 
    {
      //indicate that user was logged in by OAuth by registering id in session
      $session_oauth[$provider]['id'] = $oauth_profile['id'];
      $session_oauth[$provider]['username'] = $oauth_profile['username'];
      
      record_session_oauth($session_oauth);
      
      $message = sprintf($user->lang['OAUTH_MSG_LOGGED'], $user->data['username'], $oauth_profile['link'], $oauth_profile['name'], ucfirst($provider));

      meta_refresh(5, append_sid("{$phpbb_root_path}index.$phpEx"));
    
    }
    else 
    {      
      $message = sprintf($user->lang['OAUTH_MSG_NO_LINK'], $oauth_profile['link'], $oauth_profile['name'], $provider, append_sid($phpbb_root_path.'oauthorize.php?provider='.$provider.'&amp;action=register'));

      login_box(request_var('redirect', $phpbb_root_path.'oauthorize.php?provider='.$provider.'&amp;action=authorize'), $message);

    }
    trigger_error($message);

  break;

  // case 'authorize':
  //   //user should be logged in when in here
  //   if (!$user->data['is_registered']) 
  //   {
  //     $message = 'You have to login first to authorize a forum account.';

  //     login_box(request_var('redirect', $phpbb_root_path.'oauthorize.php?provider='.$provider.'&action=authorize'), $message);
  //     trigger_error($message);
  //   }
    
  //   // bind only to one account, if there exists another account, autologin   

  //   $sql='SELECT user_id FROM '.PROFILE_FIELDS_DATA_TABLE.' WHERE '. $oauth_column ."='". $oauth_profile['id'] . "'";
  
  //   $result = $db->sql_query($sql);
  //   $row = $db->sql_fetchrowset($result);
  //   $db->sql_freeresult($result);
       
  //   if ($row)
  //   {
  //     $message = $user->lang['OAUTH_MSG_ALREADY_MAPPED'];
  //     meta_refresh(3, $phpbb_root_path);
  //     trigger_error($message);
  //   }

  //   record_oauth_id($oauth_profile['id'], $provider);
  //   $message = sprintf($user->lang['OAUTH_MSG_MAPPED_LINK'], $oauth_profile['link'], $oauth_profile['name'], ucfirst($provider), $user->data['user_id'], $user->data['username']);

  //   // set id in session that shows account is authorized
  //   // indicate that user was logged in by OAuth by registering session
  //   $session_oauth[$provider]['id'] = $oauth_profile['id'];
  //   $session_oauth[$provider]['username'] = $oauth_profile['username'];

  //   record_session_oauth($session_oauth);  

  //   meta_refresh(3, $phpbb_root_path);
  //   trigger_error($message);

  // break;

  // case 'deauthorize':
    
  //   record_oauth_id('', $provider, false);

  //   //remove oauth data in session
  //   $session_oauth[$provider] = array();
  //   record_session_oauth($session_oauth);

  //   $message = sprintf($user->lang['OAUTH_MSG_CUT_LINK'], $oauth_profile['link'], $oauth_profile['name'], ucfirst($provider));

  //   meta_refresh(3, $phpbb_root_path);
  //   trigger_error($message);

  // break;

  // case 'register':

  //   //bind only to one account, if there exists another account, autologin that
  //   //check if oauth id already mapped to any account because you dont want to bind it if its already being used 
      
  //   $sql='SELECT user_id FROM '. PROFILE_FIELDS_DATA_TABLE . ' WHERE '.$oauth_column . " = '" . $oauth_profile['id'] . "'";
  //   $result = $db->sql_query($sql);
  //   $row = $db->sql_fetchrowset($result);
  //   $db->sql_freeresult($result);
  //   //redirect instead
  //   if ($row) 
  //   {
  //     $message = $user->lang['OAUTH_MSG_ALREADY_MAPPED'];
  //     meta_refresh(3, $phpbb_root_path);
  //     trigger_error($message);
  //   }

  //   if ($user->data['is_registered'])
  //   {
  //     $message = $user->lang['OAUTH_MSG_ALREADY_REGISTER'];
  //     meta_refresh(3, $phpbb_root_path);

  //     trigger_error($message);
  //   }
  //   else 
  //   {
  //     //set some session data
  //     $session_oauth[$provider]['id'] = $oauth_profile['id'];
  //     $session_oauth[$provider]['username'] = $oauth_profile['username'];

  //     record_session_oauth($session_oauth);

  //     redirect( append_sid( $phpbb_root_path.'ucp.php?mode=register&type=oauth&provider='.$provider) );
  //   }
  // break;
  
  default:
  
    $message = $user->lang['OAUTH_UNKOWN_ACTION'];
    meta_refresh(3, $phpbb_root_path);    
    trigger_error($message);  
}