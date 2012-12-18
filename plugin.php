<?php
/**
 * WeNotif's subcription extension's main file
 * 
 * @package Dragooon:WeNotif-Subs
 * @author Shitiz "Dragooon" Garg <Email mail@dragooon.net> <Url http://smf-media.com>
 * @copyright 2012, Shitiz "Dragooon" Garg <mail@dragooon.net>
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

        if (NotifSusbcription::get(self::$subscriptions[$type], $object) !== false)
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
        call_hook('notification_callback', array(&self::$subscriptions));

        foreach (self::$subscriptions as $type => $object)
            if (!($object instanceof NotifSusbcriber))
                unset(self::$subscriptions[$type]);
    }
}

/**
 * Base interface that every susbcriber should follow
 */
interface NotifSusbcriber
{
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
        global $user_info;

        if ($member == null)
            $member = $user_info['id'];

        $query = wesql::query('
            SELECT id_member, id_object
            FROM {db_prefix}wenotif_subs
            WHERE id_member = {int:member}
                AND id_object = {int:object}
                AND type = {string:type}
            LIMIT 1', array(
                'member' => $user_info['id'],
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
        global $user_info;

        if ($member == null)
            $member = $user_info['id'];

        wesql::insert('', '{db_prefix}wenotif_subs',
            array('id_member' => 'int', 'id_object' => 'int', 'type' => 'string'),
            array($member, $object, $subs->getName()),
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
            FROM {db_prefix}WeNotif_subs
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

        while (list($id_member) = wesql::fetch_row($request))
            $members[] = $id_member;

        wesql::free_result($request);

        $notifications = Notification::issue($members, $notifier, $data);

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
            DELETE FROM {db_prefix}WeNotif_subs
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