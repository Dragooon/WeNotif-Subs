<?php
/**
 * Template file for notification subscriptions
 * 
 * @package Dragooon:WeNotif-Subs
 * @author Shitiz "Dragooon" Garg <Email mail@dragooon.net> <Url http://smf-media.com>
 * @copyright 2012, Shitiz "Dragooon" Garg <mail@dragooon.net>
 * @license
 *      Licensed under "New BSD License (3-clause version)"
 *      http://www.opensource.org/licenses/BSD-3-Clause
 * @version 1.0
 */

function template_notification_subs_profile()
{
    global $scripturl, $txt, $context;

    echo '
        <we:cat>
            ', $txt['notif_subs'], '
        </we:cat>
        <p class="windowbg description">', $txt['notif_subs_desc'], '</p>';

    foreach ($context['notif_subscriptions'] as $subscription)
    {
        echo '
        <div class="generic_list">
            <table class="table_grid cs0" style="width: 100%;">
                <thead>
                    <tr class="catbg">
                        <th scope="cols" class="left first_th">', $subscription['profile']['label'], '</th>
                        <th scope="cols" class="left">', $txt['notif_subscribed_time'], '</th>
                        <th scope="cols" class="left last_th" style="width: 8%;"></th>
                    </tr>
                </thead>
                <tr>
                    <td class="windowbg description" colspan="4">', $subscription['profile']['description'], '</td>
                </tr>';

        $alt = false;
        foreach ($subscription['objects'] as $object)
        {
            $alt = !$alt;

            echo '
                <tr class="windowbg', $alt ? '2' : '', '">
                    <td><a href="', $object['link'], '">', $object['title'], '</a></td>
                    <td>', $object['time'], '</td>
                    <td class="center">
                        <a href="', $scripturl, '?action=subscribe;unsubscribe;id=', $object['id'], ';type=', $subscription['type'], '">
                        </a>
                    </td>
                </tr>';
        }

        echo '
            </table>
        </div>';
    }
}