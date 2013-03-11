<?php
/**
 * WeNotif's subcription extension's main file
 * 
 * @package Dragooon:WeNotif-Subs
 * @author Shitiz "Dragooon" Garg <Email mail@dragooon.net> <Url http://smf-media.com>
 * @copyright 2012-2013, Shitiz "Dragooon" Garg <mail@dragooon.net>
 * @license
 *      Licensed under "New BSD License (3-clause version)"
 *      http://www.opensource.org/licenses/BSD-3-Clause
 * @version 1.0
 */

/**
 * Our main class for handling the subscription's UI
 */
class WeNotif_Subs
{
    protected static $subscriptions = array();

    /**
     * Returns the subscribers
     *
     * @static
     * @access public
     * @param string $subscriber If specified, only returns this subscriber
     * @return array
     */
    public static function getSubscribers($subscriber = null)
    {
        return !empty($subscriber) ? self::$subscriptions[$subscriber] : self::$subscriptions;
    }

    /**
     * Main action for subscribing to a provided subcription
     *
     * @static
     * @access public
     * @return void
     */
    public static function action()
    {
        global $txt, $scripturl, $context, $user_info;

        checkSession('get');

        $object = (int) $_REQUEST['object'];
        $type = strtolower(trim($_REQUEST['type']));

        if (empty($object) || empty($type) || $user_info['is_guest'] || !isset(self::$subscriptions[$type]))
            fatal_lang_error('wenotif_subs_object_type_empty');

        // Run it by the subscription objects and see if this thing is
        // subscribable (is this a word?)
        if (!self::$subscriptions[$type]->isValidObject($object))
            fatal_lang_error('wenotif_subs_invalid_object');

        if (NotifSubscription::get(self::$subscriptions[$type], $object) !== false)
            fatal_lang_error('wenotif_subs_already_susbcribed');

        NotifSubscription::store(self::$subscriptions[$type], $object);

        redirectexit(self::$subscriptions[$type]->getURL($object));
    }

    /**
     * Hook callback for load_theme, allows registering of subsciption providers
     *
     * @static
     * @access public
     * @return void
     */
    public static function hook_load_theme()
    {
        call_hook('notification_subscription', array(&self::$subscriptions));

        loadPluginLanguage('Dragooon:WeNotif-Subs', 'languages/plugin');

        foreach (self::$subscriptions as $type => $object)
            if (!($object instanceof NotifSubscriber) || WeNotif::isNotifierDisabled($object->getNotifier()))
                unset(self::$subscriptions[$type]);
    }

    /**
     * Hook callback for "profile_areas"
     *
     * @static
     * @access public
     * @param array &$profile_areas
     * @return void
     */
    public static function hook_profile_areas(&$profile_areas)
    {
        global $scripturl, $txt, $context;

        $profile_areas['edit_profile']['areas']['notifsubs'] = array(
            'label' => $txt['notif_subs'],
            'enabled' => true,
            'function' => 'WeNotif_Subs_profile',
            'permission' => array(
                'own' => array('profile_extra_own'),
            ),
        );
    }

    /**
     * Profile area for showing subscriptions
     *
     * @static
     * @access public
     * @param int $memID
     * @return void
     */
    public static function profile($memID)
    {
        global $context, $scripturl, $txt;

        $subscriptions = array();
        $starttimes = array();
        foreach (self::$subscriptions as $type => $subscription)
        {
            $subscriptions[$type] = array(
                'type' => $type,
                'subscriber' => $subscription,
                'profile' => $subscription->getProfile($memID),
                'objects' => array(),
            );
            $starttimes[$type] = array();
        }

        $request = wesql::query('
            SELECT id_object, type, starttime
            FROM {db_prefix}notif_subs
            WHERE id_member = {int:member}',
            array(
                'member' => $memID,
            )
        );
        while ($row = wesql::fetch_assoc($request))
        {
            if (isset($subscriptions[$row['type']]))
                $subscriptions[$row['type']]['objects'][] = $row['id_object'];
            $starttimes[$row['type']][$row['id_object']] = timeformat($row['starttime']);
        }

        wesql::free_result();

        // Load individual subscription's objects
        foreach ($subscriptions as &$subscription)
            if (!empty($subscription['objects']))
            {
                $subscription['objects'] = $subscription['subscriber']->getObjects($subscription['objects']);
 
                foreach ($subscription['objects'] as $id => &$object)
                    $object['time'] = $starttimes[$subscription['type']][$id];
            }

        $context['notif_subscriptions'] = $subscriptions;

        loadPluginTemplate('Dragooon:WeNotif-Subs', 'templates/plugin');
        wetem::load('notification_subs_profile');
    }
}

function WeNotif_Subs_profile($memID)
{
    return WeNotif_Subs::profile($memID);
}

/**
 * Base interface that every susbcriber should follow
 */
interface NotifSubscriber
{
    /**
     * Returns a URL for the object
     *
     * @access public
     * @param int $object
     * @return string
     */
    public function getURL($object);

    /**
     * Returns this subscription's name
     *
     * @access public
     * @return string
     */
    public function getName();

    /**
     * Returns the Notifier object associated with this subscription
     *
     * @access public
     * @return Notifier
     */
    public function getNotifier();

    /**
     * Checks whether the passed object is valid or not for subscribing
     *
     * @access public
     * @param int $object
     * @return bool
     */
    public function isValidObject($object);

    /**
     * Returns text for profile areas which will be displayed to the user
     * Returned array will be formatted like:
     *
     * array (
     *      label => The title of the subscriber
     *      description => Any additional help text to be displayed for the user
     * )
     *
     * @access public
     * @param int $id_member
     * @return array
     */
    public function getProfile($id_member);

    /**
     * Returns the ID, name and an URL for the passed objects for this
     * subscriber. Returned array will be formatted like:
     *
     * array(
     *      [Object's ID] => array(
     *          id => Object's ID
     *          title => Plain text identifying the object (Topic's title, member's name etc)
     *          href => A fully qualified URL for the object
     *      )
     * )
     *
     * @access public
     * @param array $objects IDs of the objects to fetch
     * @return array
     */
    public function getObjects(array $objects);
}

/**
 * Handles individual and overall subscription's records
 */
class NotifSubscription
{
    protected $member;
    protected $object;
    protected $subs;

    /**
     * Fetches a single subscription for a given member
     *
     * @static
     * @access public
     * @param NotifSubscriber $subs
     * @param int $object
     * @param int $member (Defaults on current member if null)
     * @return NotifSubscription
     */
    public static function get(NotifSubscriber $subs, $object, $member = null)
    {
        if ($member == null)
            $member = we::$id;

        $query = wesql::query('
            SELECT id_member, id_object
            FROM {db_prefix}notif_subs
            WHERE id_member = {int:member}
                AND id_object = {int:object}
                AND type = {string:type}
            LIMIT 1', array(
                'member' => $member,
                'object' => $object,
                'type' => $subs->getName(),
            )
        );

        if (wesql::num_rows($query) == 0)
            return false;

        list ($id_member, $id_object) = wesql::fetch_row($query);

        wesql::free_result($request);

        return new self($id_member, $id_object, $subs);
    }

    /**
     * Stores the new subscription record, does no security check
     *
     * @static
     * @access public
     * @param NotifSubscriber $subs
     * @param int $object
     * @param int $member (Defaults on current member if null)
     * @return NotifSubscription
     */
    public static function store(NotifSubscriber $subs, $object, $member = null)
    {
        if ($member == null)
            $member = we::$id;

        wesql::insert('', '{db_prefix}notif_subs',
            array('id_member' => 'int', 'id_object' => 'int', 'type' => 'string', 'starttime' => 'int'),
            array($member, $object, $subs->getName(), time()),
            array('id_member', 'id_object', 'type')
        );

        return new self($member, $object, $subs);
    }

    /**
     * Issues a specific notification to all the members subscribed
     * to the specific notification, returns all the notifications
     * issued
     *
     * @static
     * @access public
     * @param NotifSubscriber $subs
     * @param int $object
     * @param Notifier $notifier
     * @param array $data
     * @return array of Notification
     */
    public static function issue(NotifSubscriber $subs, $object, Notifier $notifier, array $data)
    {
        // Fetch all the members having this subscription
        $query = wesql::query('
            SELECT id_member
            FROM {db_prefix}notif_subs
            WHERE type = {string:type}
                AND id_object = {int:object}',
            array(
                'type' => $subs->getName(),
                'object' => $object,
            )
        );
        if (wesql::num_rows($query) == 0)
            return array();

        $notifications = array();
        $members = array();

        while ($row = wesql::fetch_row($query))
            if ($row[0] != we::$id)
                $members[] = $row[0];

        wesql::free_result($query);

        if (empty($members))
            return array();

        $notifications = Notification::issue($members, $notifier, $object, $data);

        return $notifications;
    }

    /**
     * Basic constructor, basically cannot be called from outside
     * and only through self::get
     *
     * @access protected
     * @param int $member
     * @param int $object
     * @param NotifSubscriber $subs
     * @return void
     */
    protected function __construct($member, $object, $subs)
    {
        $this->member = $member;
        $this->object = $object;
        $this->subs = $subs;
    }

    /**
     * Deletes this subscription record
     *
     * @access public
     * @return void
     */
    public function delete()
    {
        wesql::query('', '
            DELETE FROM {db_prefix}notif_subs
            WHERE id_memebr = {int:member}
                AND id_object = {int:object}
                AND type = {string:type}',
            array(
                'member' => $this->member,
                'object' => $this->object,
                'type' => $this->subs->getName(),
            )
        );
    }
}