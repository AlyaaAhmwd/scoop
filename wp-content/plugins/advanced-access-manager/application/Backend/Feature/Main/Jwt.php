<?php

/**
 * ======================================================================
 * LICENSE: This file is subject to the terms and conditions defined in *
 * file 'license.txt', which is part of this source code package.       *
 * ======================================================================
 */

/**
 * JWT UI manager
 *
 * @since 6.9.0 https://github.com/aamplugin/advanced-access-manager/issues/221
 * @since 6.7.9 https://github.com/aamplugin/advanced-access-manager/issues/192
 * @since 6.0.0 Initial implementation of the class
 *
 * @package AAM
 * @version 6.9.0
 */
class AAM_Backend_Feature_Main_Jwt
    extends AAM_Backend_Feature_Abstract implements AAM_Backend_Feature_ISubjectAware
{

    use AAM_Core_Contract_RequestTrait;

    /**
     * Default access capability to the service
     *
     * @version 6.0.0
     */
    const ACCESS_CAPABILITY = 'aam_manage_jwt';

    /**
     * HTML template to render
     *
     * @version 6.0.0
     */
    const TEMPLATE = 'service/jwt.php';

    /**
     * Get list of tokens
     *
     * @return string
     *
     * @access public
     * @version 6.0.0
     */
    public function getTable()
    {
        return wp_json_encode($this->retrieveList());
    }

    /**
     * Generate JWT token
     *
     * @return string
     *
     * @since 6.9.8 https://github.com/aamplugin/advanced-access-manager/issues/263
     * @since 6.9.0 https://github.com/aamplugin/advanced-access-manager/issues/221
     * @since 6.0.0 Initial implementation of the method
     *
     * @access public
     * @version 6.9.8
     */
    public function generate()
    {
        $user   = AAM_Backend_Subject::getInstance();
        $result = array('status' => 'failure');

        if (current_user_can('aam_manage_jwt')) {
            $expires  = $this->getFromPost('expires');
            $refresh  = $this->getFromPost('refreshable', FILTER_VALIDATE_BOOLEAN);
            $register = $this->getFromPost('register', FILTER_VALIDATE_BOOLEAN);
            $trigger  = $this->getFromPost('trigger', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

            // Determine maximum user level
            $max = AAM::getUser()->getMaxLevel();

            // Prepare the list of claims
            $claims = array(
                'userId'      => $user->ID,
                'revocable'   => true,
                'refreshable' => ($refresh === true),
                'exp'         => (new DateTime('@' . $expires))->getTimestamp()
            );

            // If token also should contains the trigger action when it is expires,
            // then add it to the list of claims
            if (!empty($trigger)) {
                $claims['trigger'] = $trigger;
            }

            try {
                if ($max >= AAM_Core_API::maxLevel($user->allcaps)) {
                    $res = AAM_Core_Jwt_Manager::getInstance()->encode($claims);

                    if ($register === true) {
                        $status = AAM_Service_Jwt::getInstance()->registerToken(
                            $user->ID, $res->token
                        );
                    } else {
                        $status = true;
                    }

                    $result = array(
                        'status' => (!empty($status) ? 'success' : 'failure'),
                        'jwt'    => $res->token
                    );
                } else {
                    $result['reason'] = __(
                        'You are not allowed to generate JWT for this user', AAM_KEY
                    );
                }
            } catch (Exception $ex) {
                $result['reason'] = $ex->getMessage();
            }
        } else {
            $result['reason'] = __(
                'You are not allowed to manage JWT tokens', AAM_KEY
            );
        }

        return wp_json_encode($result);
    }

    /**
     * Save/register new JWT token
     *
     * @return string
     *
     * @since 6.7.9 https://github.com/aamplugin/advanced-access-manager/issues/192
     * @since 6.0.0 Initial implementation of the method
     *
     * @access public
     * @version 6.7.9
     */
    public function save()
    {
        $user   = AAM_Backend_Subject::getInstance();
        $token  = $this->getFromPost('token');
        $result = AAM_Service_Jwt::getInstance()->registerToken($user->ID, $token);

        if ($result) {
            $response = array('status' => 'success');
        } else {
            $response = array(
                'status' => 'failure',
                'reason' => __('Failed to register JWT token', AAM_KEY)
            );
        }

        return wp_json_encode($response);
    }

    /**
     * Delete existing JWT token
     *
     * @return string
     *
     * @since 6.7.9 https://github.com/aamplugin/advanced-access-manager/issues/192
     * @since 6.0.0 Initial implementation of the method
     *
     * @access public
     * @version 6.7.9
     */
    public function delete()
    {
        $user   = AAM_Backend_Subject::getInstance();
        $token  = $this->getFromPost('token');
        $result = AAM_Service_Jwt::getInstance()->revokeUserToken($user->ID, $token);

        if ($result) {
            $response = array('status' => 'success');
        } else {
            $response = array(
                'status' => 'failure',
                'reason' => __('Failed to revoke JWT token', AAM_KEY)
            );
        }

        return wp_json_encode($response);
    }

    /**
     * Retrieve list of registered JWT tokens
     *
     * @return array
     *
     * @since 6.9.0 https://github.com/aamplugin/advanced-access-manager/issues/221
     * @since 6.0.0 Initial implementation of the method
     *
     * @access protected
     * @version 6.9.0
     */
    protected function retrieveList()
    {
        $tokens = AAM_Service_Jwt::getInstance()->getTokenRegistry(
            AAM_Backend_Subject::getInstance()->ID
        );

        $response = array(
            'recordsTotal'    => count($tokens),
            'recordsFiltered' => count($tokens),
            'draw'            => AAM_Core_Request::request('draw'),
            'data'            => array(),
        );

        $issuer = AAM_Core_Jwt_Manager::getInstance();

        foreach ($tokens as $token) {
            $claims  = $issuer->validate($token);

            if (!is_wp_error($claims)) {
                $expires = new DateTime('@' . $claims->exp, new DateTimeZone('UTC'));
                $details = $expires->format('m/d/Y, H:i O');
            } else {
                $details = __('Token is no longer valid', AAM_KEY);
            }

            $response['data'][] = array(
                $token,
                add_query_arg('aam-jwt', $token, site_url()),
                !is_wp_error($claims),
                $details,
                'view,delete'
            );
        }

        return $response;
    }

    /**
     * Register JWT service UI
     *
     * @return void
     *
     * @access public
     * @version 6.0.0
     */
    public static function register()
    {
        AAM_Backend_Feature::registerFeature((object) array(
            'uid'        => 'jwt',
            'position'   => 65,
            'title'      => __('JWT Tokens', AAM_KEY),
            'capability' => self::ACCESS_CAPABILITY,
            'type'       => 'main',
            'subjects'   => array(
                AAM_Core_Subject_User::UID
            ),
            'view'       => __CLASS__
        ));
    }

}