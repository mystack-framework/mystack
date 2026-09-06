<?php

/**
 * ============================================================================
 * Class: PHCD
 * Title: Client Package & UI Manager
 * ============================================================================
 * 
 * Handles authorized, atomic client-package installations, updates, and package UI rendering. Safely integrates external dependencies into the framework ecosystem.
 * 
 * Features:
 * - Atomic client-package installation and updates.
 * - Package UI management.
 * - Authorized access and integrity checks.
 * 
 * Usage Example:
 * ```php
 * PHCD::install('vendor/package');
 * PHCD::updateAll();
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */



class PHCD {
    // Base URL for the CDNJS API to fetch library data.
    private static $apiBaseUrl = 'https://api.cdnjs.com/libraries';
    // Paths for storing CSS and JS files.
    private static $cssPath = __DIR__ . '/../src/css/';
    private static $jsPath = __DIR__ . '/../src/js/';
    // State URL path for handling CDN-related requests.
    private static $state = '/cdn';
    // Remote access must be explicitly authorized by the application.
    private static $authorize = null;

    /**
     * Initializes the PHCD class with custom paths and state.
     *
     * @param string $state The base path where CDN actions will be handled.
     * @param string $css The directory path where CSS files will be stored.
     * @param string $js The directory path where JS files will be stored.
     */
    public static function initialize($state = '/cdn', $css = __DIR__ . '/../src/css/', $js = __DIR__ . '/../src/js/', ?callable $authorize = null) {
        self::$state = $state;
        self::$cssPath = $css;
        self::$jsPath = $js;
        self::$authorize = $authorize;

        PHRO::get(self::$state, function() {
            if (!self::requireAccess(false)) {
                return;
            }
            self::webUI();
        });

        PHRO::post(self::$state, function() {
            if (!self::requireAccess(true)) {
                return;
            }
            PHDE::debug(false);
            self::handleRequest();
        });
    }

    /**
     * Renders the web UI for interacting with the CDN.
     * This is currently a placeholder method.
     */
    private static function webUI() {
        $encodedText1 = "PCFET0NUWVBFIGh0bWw+CjxodG1sIGxhbmc9ImVuIj4KCjxoZWFkPgogICAgPG1ldGEgY2hhcnNldD0iVVRGLTgiPgogICAgPG1ldGEgbmFtZT0idmlld3BvcnQiIGNvbnRlbnQ9IndpZHRoPWRldmljZS13aWR0aCwgaW5pdGlhbC1zY2FsZT0xLjAiPgogICAgPHRpdGxlPkNETiBQYWNrYWdlIE1hbmFnZXI8L3RpdGxlPgogICAgPHN0eWxlPgogICAgICAgIC8qIEJhc2ljIHJlc2V0ICovCiAgICAgICAgKiB7CiAgICAgICAgICAgIG1hcmdpbjogMDsKICAgICAgICAgICAgcGFkZGluZzogMDsKICAgICAgICAgICAgYm94LXNpemluZzogYm9yZGVyLWJveDsKICAgICAgICB9CgogICAgICAgIC8qIERhcmsgdGhlbWUgYW5kIHJlc3BvbnNpdmUgc3R5bGluZyAqLwogICAgICAgIGJvZHkgewogICAgICAgICAgICBmb250LWZhbWlseTogJ1NlZ29lIFVJJywgVGFob21hLCBHZW5ldmEsIFZlcmRhbmEsIHNhbnMtc2VyaWY7CiAgICAgICAgICAgIGJhY2tncm91bmQtY29sb3I6ICMxZTFlMmU7CiAgICAgICAgICAgIGNvbG9yOiAjYzlkMWQ5OwogICAgICAgICAgICBwYWRkaW5nOiAyMHB4OwogICAgICAgICAgICBtYXgtd2lkdGg6IDEyMDBweDsKICAgICAgICAgICAgbWFyZ2luOiAwIGF1dG87CiAgICAgICAgICAgIG92ZXJmbG93LXg6IGhpZGRlbjsKICAgICAgICAgICAgcG9zaXRpb246IHJlbGF0aXZlOwogICAgICAgIH0KICAgICAgICAKICAgICAgICBoMSB7CiAgICAgICAgICAgIHRleHQtYWxpZ246IGNlbnRlcjsKICAgICAgICAgICAgbWFyZ2luLWJvdHRvbTogMjBweDsKICAgICAgICAgICAgY29sb3I6ICNmZmZmZmY7CiAgICAgICAgfQoKICAgICAgICAudGFicyB7CiAgICAgICAgICAgIGRpc3BsYXk6IGZsZXg7CiAgICAgICAgICAgIGp1c3RpZnktY29udGVudDogc3BhY2UtYXJvdW5kOwogICAgICAgICAgICBiYWNrZ3JvdW5kLWNvbG9yOiByZ2JhKDQxLCA0MywgNjEsIDAuOCk7CiAgICAgICAgICAgIGJvcmRlci1yYWRpdXM6IDEwcHg7CiAgICAgICAgICAgIG92ZXJmbG93OiBoaWRkZW47CiAgICAgICAgICAgIG1hcmdpbi1ib3R0b206IDIwcHg7CiAgICAgICAgICAgIGJhY2tkcm9wLWZpbHRlcjogYmx1cigxMHB4KTsKICAgICAgICB9CgogICAgICAgIC50YWIgewogICAgICAgICAgICBmbGV4OiAxOwogICAgICAgICAgICBwYWRkaW5nOiAxNXB4OwogICAgICAgICAgICB0ZXh0LWFsaWduOiBjZW50ZXI7CiAgICAgICAgICAgIGN1cnNvcjogcG9pbnRlcjsKICAgICAgICAgICAgYmFja2dyb3VuZC1jb2xvcjogcmdiYSg0MSwgNDMsIDYxLCAwLjgpOwogICAgICAgICAgICB0cmFuc2l0aW9uOiBiYWNrZ3JvdW5kLWNvbG9yIDAuM3MgZWFzZTsKICAgICAgICAgICAgYm9yZGVyLWJvdHRvbTogM3B4IHNvbGlkIHRyYW5zcGFyZW50OwogICAgICAgICAgICBiYWNrZHJvcC1maWx0ZXI6IGJsdXIoMTBweCk7CiAgICAgICAgfQoKICAgICAgICAudGFiLmFjdGl2ZSB7CiAgICAgICAgICAgIGJhY2tncm91bmQtY29sb3I6IHJnYmEoNTcsIDY1LCA3OSwgMC44KTsKICAgICAgICAgICAgYm9yZGVyLWJvdHRvbTogM3B4IHNvbGlkICM0YThjZmQ7CiAgICAgICAgfQoKICAgICAgICAudGFiOmhvdmVyIHsKICAgICAgICAgICAgYmFja2dyb3VuZC1jb2xvcjogcmdiYSg1NywgNjUsIDc5LCAwLjgpOwogICAgICAgIH0KCiAgICAgICAgLnRhYi1jb250ZW50IHsKICAgICAgICAgICAgZGlzcGxheTogbm9uZTsKICAgICAgICAgICAgYW5pbWF0aW9uOiBmYWRlSW4gMC41czsKICAgICAgICAgICAgYmFja2Ryb3AtZmlsdGVyOiBibHVyKDEwcHgpOwogICAgICAgIH0KCiAgICAgICAgLnRhYi1jb250ZW50LmFjdGl2ZSB7CiAgICAgICAgICAgIGRpc3BsYXk6IGJsb2NrOwogICAgICAgIH0KCiAgICAgICAgQGtleWZyYW1lcyBmYWRlSW4gewogICAgICAgICAgICBmcm9tIHsKICAgICAgICAgICAgICAgIG9wYWNpdHk6IDA7CiAgICAgICAgICAgIH0KICAgICAgICAgICAgdG8gewogICAgICAgICAgICAgICAgb3BhY2l0eTogMTsKICAgICAgICAgICAgfQogICAgICAgIH0KCiAgICAgICAgLnNlYXJjaC1ib3ggewogICAgICAgICAgICBtYXJnaW4tYm90dG9tOiAyMHB4OwogICAgICAgICAgICB0ZXh0LWFsaWduOiBjZW50ZXI7CiAgICAgICAgfQoKICAgICAgICAuc2VhcmNoLWJveCBpbnB1dCB7CiAgICAgICAgICAgIHdpZHRoOiAxMDAlOwogICAgICAgICAgICBtYXgtd2lkdGg6IDYwMHB4OwogICAgICAgICAgICBwYWRkaW5nOiAxMHB4OwogICAgICAgICAgICBib3JkZXI6IG5vbmU7CiAgICAgICAgICAgIGJvcmRlci1yYWRpdXM6IDVweDsKICAgICAgICAgICAgYmFja2dyb3VuZC1jb2xvcjogcmdiYSg0MSwgNDMsIDYxLCAwLjgpOwogICAgICAgICAgICBjb2xvcjogI2ZmZmZmZjsKICAgICAgICAgICAgZm9udC1zaXplOiAxNnB4OwogICAgICAgICAgICBvdXRsaW5lOiBub25lOwogICAgICAgICAgICB0cmFuc2l0aW9uOiBhbGwgMC4zcyBlYXNlOwogICAgICAgICAgICBiYWNrZHJvcC1maWx0ZXI6IGJsdXIoMTBweCk7CiAgICAgICAgfQoKICAgICAgICAuc2VhcmNoLWJveCBpbnB1dDpmb2N1cyB7CiAgICAgICAgICAgIGJvcmRlcjogMnB4IHNvbGlkICM0YThjZmQ7CiAgICAgICAgfQoKICAgICAgICAucmVzdWx0cywKICAgICAgICAuaW5zdGFsbGVkLXBhY2thZ2VzLAogICAgICAgIC51cGRhdGUtcGFja2FnZXMgewogICAgICAgICAgICBtYXJnaW4tdG9wOiAyMHB4OwogICAgICAgICAgICBkaXNwbGF5OiBmbGV4OwogICAgICAgICAgICBmbGV4LWRpcmVjdGlvbjogY29sdW1uOwogICAgICAgICAgICBnYXA6IDEwcHg7CiAgICAgICAgfQoKICAgICAgICAucmVzdWx0LWl0ZW0sCiAgICAgICAgLmluc3RhbGxlZC1pdGVtLAogICAgICAgIC51cGRhdGUtaXRlbSB7CiAgICAgICAgICAgIGJhY2tncm91bmQtY29sb3I6IHJnYmEoNDEsIDQzLCA2MSwgMC44KTsKICAgICAgICAgICAgcGFkZGluZzogMTVweDsKICAgICAgICAgICAgYm9yZGVyLXJhZGl1czogMTBweDsKICAgICAgICAgICAgZGlzcGxheTogZmxleDsKICAgICAgICAgICAganVzdGlmeS1jb250ZW50OiBzcGFjZS1iZXR3ZWVuOwogICAgICAgICAgICBhbGlnbi1pdGVtczogY2VudGVyOwogICAgICAgICAgICB0cmFuc2l0aW9uOiB0cmFuc2Zvcm0gMC4zcyBlYXNlOwogICAgICAgICAgICBiYWNrZHJvcC1maWx0ZXI6IGJsdXIoMTBweCk7CiAgICAgICAgfQoKICAgICAgICAucmVzdWx0LWl0ZW06aG92ZXIsCiAgICAgICAgLmluc3RhbGxlZC1pdGVtOmhvdmVyLAogICAgICAgIC51cGRhdGUtaXRlbTpob3ZlciB7CiAgICAgICAgICAgIHRyYW5zZm9ybTogdHJhbnNsYXRlWSgtNXB4KTsKICAgICAgICB9CgogICAgICAgIC5yZXN1bHQtaXRlbSBzdHJvbmcsCiAgICAgICAgLmluc3RhbGxlZC1pdGVtIHN0cm9uZywKICAgICAgICAudXBkYXRlLWl0ZW0gc3Ryb25nIHsKICAgICAgICAgICAgY29sb3I6ICM0YThjZmQ7CiAgICAgICAgfQoKICAgICAgICAucmVzdWx0LWl0ZW0gYnV0dG9uLAogICAgICAgIC5pbnN0YWxsZWQtaXRlbSBidXR0b24sCiAgICAgICAgLnVwZGF0ZS1pdGVtIGJ1dHRvbiB7CiAgICAgICAgICAgIHBhZGRpbmc6IDhweCAxNXB4OwogICAgICAgICAgICBib3JkZXI6IG5vbmU7CiAgICAgICAgICAgIGJvcmRlci1yYWRpdXM6IDVweDsKICAgICAgICAgICAgYmFja2dyb3VuZC1jb2xvcjogIzRhOGNmZDsKICAgICAgICAgICAgY29sb3I6ICNmZmZmZmY7CiAgICAgICAgICAgIGN1cnNvcjogcG9pbnRlcjsKICAgICAgICAgICAgdHJhbnNpdGlvbjogYmFja2dyb3VuZC1jb2xvciAwLjNzIGVhc2U7CiAgICAgICAgICAgIG1hcmdpbi1sZWZ0OiAxMHB4OwogICAgICAgICAgICBiYWNrZHJvcC1maWx0ZXI6IGJsdXIoMTBweCk7CiAgICAgICAgfQoKICAgICAgICAucmVzdWx0LWl0ZW0gYnV0dG9uOmhvdmVyLAogICAgICAgIC5pbnN0YWxsZWQtaXRlbSBidXR0b246aG92ZXIsCiAgICAgICAgLnVwZGF0ZS1pdGVtIGJ1dHRvbjpob3ZlciB7CiAgICAgICAgICAgIGJhY2tncm91bmQtY29sb3I6ICMzNzZmZDY7CiAgICAgICAgfQoKICAgICAgICAucmVzdWx0LWl0ZW0tYnV0dG9ucywKICAgICAgICAuaW5zdGFsbGVkLWl0ZW0tYnV0dG9ucywKICAgICAgICAudXBkYXRlLWl0ZW0tYnV0dG9ucyB7CiAgICAgICAgICAgIGRpc3BsYXk6IGZsZXg7CiAgICAgICAgICAgIGp1c3RpZnktY29udGVudDogZmxleC1lbmQ7CiAgICAgICAgICAgIGFsaWduLWl0ZW1zOiBjZW50ZXI7CiAgICAgICAgICAgIGdhcDogMTBweDsKICAgICAgICAgICAgZmxleC13cmFwOiB3cmFwOwogICAgICAgIH0KCiAgICAgICAgLyogUmVzcG9uc2l2ZSBkZXNpZ24gKi8KICAgICAgICBAbWVkaWEgKG1heC13aWR0aDogNzY4cHgpIHsKICAgICAgICAgICAgLnNlYXJjaC1ib3ggaW5wdXQgewogICAgICAgICAgICAgICAgd2lkdGg6IDEwMCU7CiAgICAgICAgICAgIH0KCiAgICAgICAgICAgIC50YWJzIHsKICAgICAgICAgICAgICAgIGZsZXgtZGlyZWN0aW9uOiBjb2x1bW47CiAgICAgICAgICAgIH0KCiAgICAgICAgICAgIC5yZXN1bHQtaXRlbSwKICAgICAgICAgICAgLmluc3RhbGxlZC1pdGVtLAogICAgICAgICAgICAudXBkYXRlLWl0ZW0gewogICAgICAgICAgICAgICAgZmxleC1kaXJlY3Rpb246IGNvbHVtbjsKICAgICAgICAgICAgICAgIGFsaWduLWl0ZW1zOiBmbGV4LXN0YXJ0OwogICAgICAgICAgICB9CgogICAgICAgICAgICAucmVzdWx0LWl0ZW0gYnV0dG9uLAogICAgICAgICAgICAuaW5zdGFsbGVkLWl0ZW0gYnV0dG9uLAogICAgICAgICAgICAudXBkYXRlLWl0ZW0gYnV0dG9uIHsKICAgICAgICAgICAgICAgIG1hcmdpbi10b3A6IDEwcHg7CiAgICAgICAgICAgICAgICB3aWR0aDogMTAwJTsKICAgICAgICAgICAgICAgIHRleHQtYWxpZ246IGNlbnRlcjsKICAgICAgICAgICAgfQogICAgICAgIH0KCiAgICAgICAgLyogRm9vdGVyICovCiAgICAgICAgLmZvb3RlciB7CiAgICAgICAgICAgIHBvc2l0aW9uOiBmaXhlZDsKICAgICAgICAgICAgYm90dG9tOiAwOwogICAgICAgICAgICBsZWZ0OiAwOwogICAgICAgICAgICB3aWR0aDogMTAwJTsKICAgICAgICAgICAgYmFja2dyb3VuZC1jb2xvcjogcmdiYSg0MSwgNDMsIDYxLCAwLjgpOwogICAgICAgICAgICBjb2xvcjogI2M5ZDFkOTsKICAgICAgICAgICAgdGV4dC1hbGlnbjogY2VudGVyOwogICAgICAgICAgICBwYWRkaW5nOiAxMHB4OwogICAgICAgICAgICBiYWNrZHJvcC1maWx0ZXI6IGJsdXIoMTBweCk7CiAgICAgICAgfQoKICAgICAgICAvKiBNb2RhbHMgKi8KICAgICAgICAubW9kYWwgewogICAgICAgICAgICBkaXNwbGF5OiBub25lOwogICAgICAgICAgICBwb3NpdGlvbjogZml4ZWQ7CiAgICAgICAgICAgIHRvcDogMDsKICAgICAgICAgICAgbGVmdDogMDsKICAgICAgICAgICAgd2lkdGg6IDEwMCU7CiAgICAgICAgICAgIGhlaWdodDogMTAwJTsKICAgICAgICAgICAgYmFja2dyb3VuZDogcmdiYSgwLCAwLCAwLCAwLjUpOwogICAgICAgICAgICBqdXN0aWZ5LWNvbnRlbnQ6IGNlbnRlcjsKICAgICAgICAgICAgYWxpZ24taXRlbXM6IGNlbnRlcjsKICAgICAgICAgICAgYmFja2Ryb3AtZmlsdGVyOiBibHVyKDEwcHgpOwogICAgICAgICAgICBvdmVyZmxvdy15OiBhdXRvOwogICAgICAgICAgICB6LWluZGV4OiAxMDAwOwogICAgICAgIH0KCiAgICAgICAgLm1vZGFsLWNvbnRlbnQgewogICAgICAgICAgICBiYWNrZ3JvdW5kOiByZ2JhKDQxLCA0MywgNjEsIDAuOSk7CiAgICAgICAgICAgIGJvcmRlci1yYWRpdXM6IDEwcHg7CiAgICAgICAgICAgIHBhZGRpbmc6IDIwcHg7CiAgICAgICAgICAgIG1heC13aWR0aDogNTAwcHg7CiAgICAgICAgICAgIHdpZHRoOiAxMDAlOwogICAgICAgICAgICB0ZXh0LWFsaWduOiBjZW50ZXI7CiAgICAgICAgICAgIG1heC1oZWlnaHQ6IDgwdmg7IC8qIFJlc3RyaWN0IG1vZGFsIGhlaWdodCAqLwogICAgICAgICAgICBvdmVyZmxvdy15OiBhdXRvOyAvKiBFbmFibGUgc2Nyb2xsaW5nIGlmIGNvbnRlbnQgaXMgdG9vIGxvbmcgKi8KICAgICAgICB9CgogICAgICAgIC5tb2RhbC1mb290ZXIgewogICAgICAgICAgICBwYWRkaW5nOiAycHg7CiAgICAgICAgICAgIGJhY2tncm91bmQtY29sb3I6IHJnYmEoNDEsIDQzLCA2MSwgMC44KTsKICAgICAgICAgICAgYm9yZGVyLXRvcDogMXB4IHNvbGlkICM0YThjZmQ7CiAgICAgICAgfQoKICAgICAgICAubW9kYWwtaGVhZGVyIHsKICAgICAgICAgICAgcGFkZGluZzogMnB4OwogICAgICAgICAgICBiYWNrZ3JvdW5kLWNvbG9yOiByZ2JhKDQxLCA0MywgNjEsIDAuOCk7CiAgICAgICAgICAgIGJvcmRlci1ib3R0b206IDFweCBzb2xpZCAjNGE4Y2ZkOwogICAgICAgICAgICBwb3NpdGlvbjogc3RpY2t5OwogICAgICAgICAgICB0b3A6IDA7CiAgICAgICAgICAgIHotaW5kZXg6IDE7CiAgICAgICAgfQoKICAgICAgICAubW9kYWwtY29udGVudCBoMiB7CiAgICAgICAgICAgIG1hcmdpbi1ib3R0b206IDEwcHg7CiAgICAgICAgICAgIGNvbG9yOiAjZmZmZmZmOwogICAgICAgIH0KICAgICAgICAjbW9kYWwtZGV0YWlscy1tb2RhbC1jb250ZW50IHsKICAgICAgICAgICAgbWFyZ2luLXRvcDogMTBweDsKICAgICAgICAgICAgbWFyZ2luLWJvdHRvbTogMTBweDsKICAgICAgICB9CgogICAgICAgIC5tb2RhbC1jb250ZW50IGJ1dHRvbiB7CiAgICAgICAgICAgIGJhY2tncm91bmQtY29sb3I6ICM0YThjZmQ7CiAgICAgICAgICAgIGNvbG9yOiAjZmZmZmZmOwogICAgICAgICAgICBib3JkZXI6IG5vbmU7CiAgICAgICAgICAgIHBhZGRpbmc6IDEwcHggMjBweDsKICAgICAgICAgICAgYm9yZGVyLXJhZGl1czogNXB4OwogICAgICAgICAgICBjdXJzb3I6IHBvaW50ZXI7CiAgICAgICAgICAgIG1hcmdpbi10b3A6IDEwcHg7CiAgICAgICAgfQoKICAgICAgICAubW9kYWwtY29udGVudCBidXR0b246aG92ZXIgewogICAgICAgICAgICBiYWNrZ3JvdW5kLWNvbG9yOiAjMzc2ZmQ2OwogICAgICAgIH0KCiAgICAgICAgLnRvYXN0IHsKICAgICAgICAgICAgcG9zaXRpb246IGZpeGVkOwogICAgICAgICAgICBib3R0b206IDIwcHg7CiAgICAgICAgICAgIHJpZ2h0OiAyMHB4OwogICAgICAgICAgICBiYWNrZ3JvdW5kOiByZ2JhKDQxLCA0MywgNjEsIDAuOSk7CiAgICAgICAgICAgIGNvbG9yOiAjZmZmZmZmOwogICAgICAgICAgICBwYWRkaW5nOiAxMHB4IDIwcHg7CiAgICAgICAgICAgIGJvcmRlci1yYWRpdXM6IDVweDsKICAgICAgICAgICAgZGlzcGxheTogbm9uZTsKICAgICAgICB9CiAgICA8L3N0eWxlPgo8L2hlYWQ+Cgo8Ym9keT4KICAgIDxoMT5DRE4gUGFja2FnZSBNYW5hZ2VyPC9oMT4KICAgIDwhLS0gVGFiIG5hdmlnYXRpb24gLS0+CiAgICA8ZGl2IGNsYXNzPSJ0YWJzIj4KICAgICAgICA8ZGl2IGNsYXNzPSJ0YWIgYWN0aXZlIiBkYXRhLXRhYj0ic2VhcmNoLXRhYiI+U2VhcmNoPC9kaXY+CiAgICAgICAgPGRpdiBjbGFzcz0idGFiIiBkYXRhLXRhYj0iaW5zdGFsbGVkLXRhYiI+SW5zdGFsbGVkPC9kaXY+CiAgICAgICAgPGRpdiBjbGFzcz0idGFiIiBkYXRhLXRhYj0idXBkYXRlLXRhYiI+VXBkYXRlPC9kaXY+CiAgICA8L2Rpdj4KICAgIDwhLS0gVGFiIGNvbnRlbnRzIC0tPgogICAgPGRpdiBpZD0ic2VhcmNoLXRhYiIgY2xhc3M9InRhYi1jb250ZW50IGFjdGl2ZSI+CiAgICAgICAgPGRpdiBjbGFzcz0ic2VhcmNoLWJveCI+CiAgICAgICAgICAgIDxpbnB1dCB0eXBlPSJ0ZXh0IiBpZD0ic2VhcmNoLWlucHV0IiBwbGFjZWhvbGRlcj0iU2VhcmNoIGZvciBhIHBhY2thZ2UuLi4iPgogICAgICAgIDwvZGl2PgogICAgICAgIDxkaXYgY2xhc3M9InJlc3VsdHMiIGlkPSJyZXN1bHRzIj48L2Rpdj4KICAgIDwvZGl2PgogICAgPGRpdiBpZD0iaW5zdGFsbGVkLXRhYiIgY2xhc3M9InRhYi1jb250ZW50Ij4KICAgICAgICA8ZGl2IGNsYXNzPSJpbnN0YWxsZWQtcGFja2FnZXMiIGlkPSJpbnN0YWxsZWQtcGFja2FnZXMiPgogICAgICAgICAgICA8IS0tIEluc3RhbGxlZCBwYWNrYWdlcyB3aWxsIGJlIGxpc3RlZCBoZXJlIC0tPgogICAgICAgIDwvZGl2PgogICAgPC9kaXY+CiAgICA8ZGl2IGlkPSJ1cGRhdGUtdGFiIiBjbGFzcz0idGFiLWNvbnRlbnQiPgogICAgICAgIDxkaXYgY2xhc3M9InVwZGF0ZS1wYWNrYWdlcyIgaWQ9InVwZGF0ZS1wYWNrYWdlcyI+CiAgICAgICAgICAgIDwhLS0gUGFja2FnZXMgbmVlZGluZyB1cGRhdGVzIHdpbGwgYmUgbGlzdGVkIGhlcmUgLS0+CiAgICAgICAgPC9kaXY+CiAgICA8L2Rpdj4KICAgIDwhLS0gRm9vdGVyIC0tPgogICAgPGRpdiBjbGFzcz0iZm9vdGVyIj4KICAgICAgICAmY29weTsgMjAyNCBDRE4gUGFja2FnZSBNYW5hZ2VyCiAgICA8L2Rpdj4KICAgIDwhLS0gTW9kYWxzIC0tPgogICAgPGRpdiBpZD0iZGV0YWlscy1tb2RhbCIgY2xhc3M9Im1vZGFsIj4KICAgICAgICA8ZGl2IGNsYXNzPSJtb2RhbC1jb250ZW50Ij4KICAgICAgICAgICAgPGRpdiBjbGFzcz0ibW9kYWwtaGVhZGVyIj4KICAgICAgICAgICAgICAgIDxoMj5QYWNrYWdlIERldGFpbHM8L2gyPgogICAgICAgICAgICA8L2Rpdj4KICAgICAgICAgICAgPGRpdiBpZD0ibW9kYWwtZGV0YWlscy1tb2RhbC1jb250ZW50Ij4KICAgICAgICAgICAgICAgIDwhLS0gQ29udGVudCB3aWxsIGJlIGR5bmFtaWNhbGx5IGluc2VydGVkIGhlcmUgLS0+CiAgICAgICAgICAgIDwvZGl2PgogICAgICAgICAgICA8ZGl2IGNsYXNzPSJtb2RhbC1mb290ZXIiPgogICAgICAgICAgICAgICAgPGJ1dHRvbiBvbmNsaWNrPSJjbG9zZU1vZGFsKCdkZXRhaWxzLW1vZGFsJykiPkNsb3NlPC9idXR0b24+CiAgICAgICAgICAgIDwvZGl2PgogICAgICAgIDwvZGl2PgogICAgPC9kaXY+CiAgICA8ZGl2IGlkPSJ2ZXJzaW9ucy1tb2RhbCIgY2xhc3M9Im1vZGFsIj4KICAgICAgICA8ZGl2IGNsYXNzPSJtb2RhbC1jb250ZW50Ij4KICAgICAgICAgICAgPGRpdiBjbGFzcz0ibW9kYWwtaGVhZGVyIj4KICAgICAgICAgICAgICAgIDxoMj5BdmFpbGFibGUgVmVyc2lvbnM8L2gyPgogICAgICAgICAgICA8L2Rpdj4KICAgICAgICAgICAgPGRpdiBpZD0ibW9kYWwtdmVyc2lvbnMtbW9kYWwtY29udGVudCI+CiAgICAgICAgICAgICAgICA8IS0tIENvbnRlbnQgd2lsbCBiZSBkeW5hbWljYWxseSBpbnNlcnRlZCBoZXJlIC0tPgogICAgICAgICAgICA8L2Rpdj4KICAgICAgICAgICAgPGRpdiBjbGFzcz0ibW9kYWwtZm9vdGVyIj4KICAgICAgICAgICAgICAgIDxidXR0b24gb25jbGljaz0iY2xvc2VNb2RhbCgndmVyc2lvbnMtbW9kYWwnKSI+Q2xvc2U8L2J1dHRvbj4KICAgICAgICAgICAgPC9kaXY+CiAgICAgICAgPC9kaXY+CiAgICA8L2Rpdj4KICAgIDxkaXYgaWQ9InRvYXN0IiBjbGFzcz0idG9hc3QiPjwvZGl2PgogICAgPHNjcmlwdD4KICAgICAgICBjb25zdCBhcGlCYXNlID0gJw==";
        $decodedText1 = base64_decode($encodedText1);
        $encodedText2 = "JzsKICAgICAgICBjb25zdCB0YWJzID0gZG9jdW1lbnQucXVlcnlTZWxlY3RvckFsbCgnLnRhYicpOwogICAgICAgIGNvbnN0IHRhYkNvbnRlbnRzID0gZG9jdW1lbnQucXVlcnlTZWxlY3RvckFsbCgnLnRhYi1jb250ZW50Jyk7CiAgICAKICAgICAgICBmdW5jdGlvbiBzaG93VGFiKHRhYklkKSB7CiAgICAgICAgICAgIHRhYnMuZm9yRWFjaCh0YWIgPT4gdGFiLmNsYXNzTGlzdC5yZW1vdmUoJ2FjdGl2ZScpKTsKICAgICAgICAgICAgdGFiQ29udGVudHMuZm9yRWFjaChjb250ZW50ID0+IGNvbnRlbnQuY2xhc3NMaXN0LnJlbW92ZSgnYWN0aXZlJykpOwogICAgICAgICAgICBkb2N1bWVudC5xdWVyeVNlbGVjdG9yKGBbZGF0YS10YWI9IiR7dGFiSWR9Il1gKS5jbGFzc0xpc3QuYWRkKCdhY3RpdmUnKTsKICAgICAgICAgICAgZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQodGFiSWQpLmNsYXNzTGlzdC5hZGQoJ2FjdGl2ZScpOwogICAgICAgIH0KICAgIAogICAgICAgIGZ1bmN0aW9uIHNob3dUb2FzdChtZXNzYWdlKSB7CiAgICAgICAgICAgIGNvbnN0IHRvYXN0ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ3RvYXN0Jyk7CiAgICAgICAgICAgIHRvYXN0LnRleHRDb250ZW50ID0gbWVzc2FnZTsKICAgICAgICAgICAgdG9hc3Quc3R5bGUuZGlzcGxheSA9ICdibG9jayc7CiAgICAgICAgICAgIHNldFRpbWVvdXQoKCkgPT4gewogICAgICAgICAgICAgICAgdG9hc3Quc3R5bGUuZGlzcGxheSA9ICdub25lJzsKICAgICAgICAgICAgfSwgMzAwMCk7CiAgICAgICAgfQogICAgCiAgICAJZnVuY3Rpb24gc2hvd01vZGFsKG1vZGFsSWQsIGNvbnRlbnQpIHsKICAgIAkJY29uc3QgbW9kYWwgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZChtb2RhbElkKTsKICAgIAkJY29uc3QgbW9kYWxDb250ZW50ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoYG1vZGFsLSR7bW9kYWxJZH0tY29udGVudGApOwoKICAgIAkJaWYgKG1vZGFsICYmIG1vZGFsQ29udGVudCkgewogICAgCQkJbW9kYWxDb250ZW50LmlubmVySFRNTCA9IGNvbnRlbnQ7CiAgICAJCQltb2RhbC5zdHlsZS5kaXNwbGF5ID0gJ2ZsZXgnOwogICAgICAgICAgICAgICAgZG9jdW1lbnQuYm9keS5zdHlsZS5vdmVyZmxvdyA9ICdoaWRkZW4nOwogICAgCQl9IGVsc2UgewogICAgCQkJY29uc29sZS5lcnJvcihgTW9kYWwgb3IgbW9kYWwgY29udGVudCBub3QgZm91bmQgZm9yIElEOiAke21vZGFsSWR9YCk7CiAgICAJCX0KICAgIAl9CiAgICAKICAgIAlmdW5jdGlvbiBjbG9zZU1vZGFsKG1vZGFsSWQpIHsKICAgIAkJY29uc3QgbW9kYWwgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZChtb2RhbElkKTsKICAgIAkJaWYgKG1vZGFsKSB7CiAgICAgICAgICAgICAgICBkb2N1bWVudC5ib2R5LnN0eWxlLm92ZXJmbG93ID0gJ2F1dG8nOwogICAgCQkJbW9kYWwuc3R5bGUuZGlzcGxheSA9ICdub25lJzsKICAgIAkJfQogICAgCX0KICAgIAogICAgICAgIGZ1bmN0aW9uIGZldGNoUmVzdWx0cyhxdWVyeSkgewogICAgICAgICAgICBpZiAocXVlcnkubGVuZ3RoID4gMikgewogICAgICAgICAgICAgICAgZmV0Y2goYXBpQmFzZSwgewogICAgICAgICAgICAgICAgICAgIG1ldGhvZDogJ1BPU1QnLAogICAgICAgICAgICAgICAgICAgIGhlYWRlcnM6IHsKICAgICAgICAgICAgICAgICAgICAgICAgJ0NvbnRlbnQtVHlwZSc6ICdhcHBsaWNhdGlvbi94LXd3dy1mb3JtLXVybGVuY29kZWQnCiAgICAgICAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgICAgICAgICBib2R5OiBgYWN0aW9uPXNlYXJjaCZxdWVyeT0ke2VuY29kZVVSSUNvbXBvbmVudChxdWVyeSl9YAogICAgICAgICAgICAgICAgfSkKICAgICAgICAgICAgICAgIC50aGVuKHJlc3BvbnNlID0+IHJlc3BvbnNlLmpzb24oKSkKICAgICAgICAgICAgICAgIC50aGVuKGRhdGEgPT4gewogICAgICAgICAgICAgICAgICAgIGNvbnN0IHJlc3VsdHNDb250YWluZXIgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgncmVzdWx0cycpOwogICAgICAgICAgICAgICAgICAgIHJlc3VsdHNDb250YWluZXIuaW5uZXJIVE1MID0gJyc7CiAgICAgICAgICAgICAgICAgICAgZGF0YS5yZXN1bHRzLmZvckVhY2goaXRlbSA9PiB7CiAgICAgICAgICAgICAgICAgICAgICAgIGNvbnN0IGl0ZW1FbGVtZW50ID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnZGl2Jyk7CiAgICAgICAgICAgICAgICAgICAgICAgIGl0ZW1FbGVtZW50LmNsYXNzTGlzdC5hZGQoJ3Jlc3VsdC1pdGVtJyk7CiAgICAgICAgICAgICAgICAgICAgICAgIGl0ZW1FbGVtZW50LmlubmVySFRNTCA9IGAKICAgICAgICAgICAgICAgICAgICAgICAgICAgIDxzdHJvbmc+JHtpdGVtLm5hbWV9QCR7aXRlbS5sYXRlc3RfdmVyc2lvbn08L3N0cm9uZz5gOwogICAgICAgICAgICAgICAgICAgICAgICBsZXQgaW5uZXJIVE1MID0gJyc7CiAgICAgICAgICAgICAgICAgICAgICAgIGlubmVySFRNTCA9IGAKICAgICAgICAgICAgICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9InJlc3VsdC1pdGVtLWJ1dHRvbnMiPgogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIDxidXR0b24gb25jbGljaz0ic2hvd0RldGFpbHMoJyR7aXRlbS5uYW1lfScsJyR7aXRlbS5sYXRlc3RfdmVyc2lvbn0nLCcke2l0ZW0uZGVzY3JpcHRpb259JykiPkRldGFpbHM8L2J1dHRvbj5gOwogICAgCQkJCQlpZiAoaXRlbS5pc19pbnN0YWxsZWQpIHsKICAgIAkJCQkJCWlubmVySFRNTCArPSBgPGJ1dHRvbiBvbmNsaWNrPSJpbnN0YWxsUGFja2FnZSgnJHtpdGVtLm5hbWV9JywgJyR7aXRlbS5sYXRlc3RfdmVyc2lvbn0nLCB0cnVlKSI+UmVpbnN0YWxsPC9idXR0b24+YDsKICAgIAkJCQkJfSBlbHNlIHsKICAgIAkJCQkJCWlubmVySFRNTCArPSBgPGJ1dHRvbiBvbmNsaWNrPSJpbnN0YWxsUGFja2FnZSgnJHtpdGVtLm5hbWV9JywgJyR7aXRlbS5sYXRlc3RfdmVyc2lvbn0nLCBmYWxzZSkiPkluc3RhbGw8L2J1dHRvbj5gOwogICAgCQkJCQl9CiAgICAJCQkJCS8vIGlubmVySFRNTCArPSBgPGJ1dHRvbiBvbmNsaWNrPSJzaG93QXZhaWxhYmxlVmVyc2lvbnMoJyR7aXRlbS5uYW1lfScpIj5JbnN0YWxsIEFub3RoZXIgVmVyc2lvbjwvYnV0dG9uPmA7CiAgICAJCQkJCWlubmVySFRNTCArPSBgCiAgICAgICAgICAgICAgICAgICAgICAgICAgICA8L2Rpdj4KICAgICAgICAgICAgICAgICAgICAgICAgYDsKICAgICAgICAgICAgICAgICAgICAgICAgaXRlbUVsZW1lbnQuaW5uZXJIVE1MICs9IGlubmVySFRNTDsKICAgICAgICAgICAgICAgICAgICAgICAgcmVzdWx0c0NvbnRhaW5lci5hcHBlbmRDaGlsZChpdGVtRWxlbWVudCk7CiAgICAgICAgICAgICAgICAgICAgfSk7CiAgICAgICAgICAgICAgICB9KQogICAgICAgICAgICAgICAgLmNhdGNoKCgpID0+IHsKICAgICAgICAgICAgICAgICAgICBzaG93VG9hc3QoJ0ZhaWxlZCB0byBmZXRjaCByZXN1bHRzLicpOwogICAgICAgICAgICAgICAgfSk7CiAgICAgICAgICAgIH0KICAgICAgICB9CiAgICAKICAgICAgICBmdW5jdGlvbiBmZXRjaERlZnVsdCgpIHsKICAgIAkJZmV0Y2goYXBpQmFzZSwgewogICAgCQkJbWV0aG9kOiAnUE9TVCcsCiAgICAJCQloZWFkZXJzOiB7CiAgICAJCQkJJ0NvbnRlbnQtVHlwZSc6ICdhcHBsaWNhdGlvbi94LXd3dy1mb3JtLXVybGVuY29kZWQnCiAgICAJCQl9LAogICAgCQkJYm9keTogYGFjdGlvbj1zZWFyY2gmcXVlcnk9MGAKICAgIAkJfSkKICAgIAkJLnRoZW4ocmVzcG9uc2UgPT4gcmVzcG9uc2UuanNvbigpKQogICAgCQkudGhlbihkYXRhID0+IHsKICAgIAkJCWNvbnN0IHJlc3VsdHNDb250YWluZXIgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgncmVzdWx0cycpOwogICAgCQkJcmVzdWx0c0NvbnRhaW5lci5pbm5lckhUTUwgPSAnJzsKICAgIAkJCWRhdGEucmVzdWx0cy5mb3JFYWNoKGl0ZW0gPT4gewogICAgCQkJCWNvbnN0IGl0ZW1FbGVtZW50ID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnZGl2Jyk7CiAgICAJCQkJaXRlbUVsZW1lbnQuY2xhc3NMaXN0LmFkZCgncmVzdWx0LWl0ZW0nKTsKICAgIAkJCQlpdGVtRWxlbWVudC5pbm5lckhUTUwgPSBgCiAgICAJCQkJCTxzdHJvbmc+JHtpdGVtLm5hbWV9QCR7aXRlbS5sYXRlc3RfdmVyc2lvbn08L3N0cm9uZz5gOwogICAgICAgICAgICAgICAgICAgIGxldCBpbm5lckhUTUwgPSAnJzsKICAgIAkJCQlpbm5lckhUTUwgPSBgCiAgICAgICAgICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9InJlc3VsdC1pdGVtLWJ1dHRvbnMiPgogICAgCQkJCQkJPGJ1dHRvbiBvbmNsaWNrPSJzaG93RGV0YWlscygnJHtpdGVtLm5hbWV9JywnJHtpdGVtLmxhdGVzdF92ZXJzaW9ufScsJyR7aXRlbS5kZXNjcmlwdGlvbn0nKSI+RGV0YWlsczwvYnV0dG9uPmA7CiAgICAJCQkJaWYgKGl0ZW0uaXNfaW5zdGFsbGVkKSB7CiAgICAJCQkJCWlubmVySFRNTCArPSBgPGJ1dHRvbiBvbmNsaWNrPSJpbnN0YWxsUGFja2FnZSgnJHtpdGVtLm5hbWV9JywgJyR7aXRlbS5sYXRlc3RfdmVyc2lvbn0nLCB0cnVlKSI+UmVpbnN0YWxsPC9idXR0b24+YDsKICAgIAkJCQl9IGVsc2UgewogICAgCQkJCQlpbm5lckhUTUwgKz0gYDxidXR0b24gb25jbGljaz0iaW5zdGFsbFBhY2thZ2UoJyR7aXRlbS5uYW1lfScsICcke2l0ZW0ubGF0ZXN0X3ZlcnNpb259JywgZmFsc2UpIj5JbnN0YWxsPC9idXR0b24+YDsKICAgIAkJCQl9CiAgICAJCQkJLy8gaW5uZXJIVE1MICs9IGA8YnV0dG9uIG9uY2xpY2s9InNob3dBdmFpbGFibGVWZXJzaW9ucygnJHtpdGVtLm5hbWV9JykiPkluc3RhbGwgQW5vdGhlciBWZXJzaW9uPC9idXR0b24+YDsKICAgIAkJCQlpbm5lckhUTUwgKz0gYAogICAgCQkJCQk8L2Rpdj4KICAgIAkJCQlgOwogICAgICAgICAgICAgICAgICAgIGl0ZW1FbGVtZW50LmlubmVySFRNTCArPSBpbm5lckhUTUw7CiAgICAJCQkJcmVzdWx0c0NvbnRhaW5lci5hcHBlbmRDaGlsZChpdGVtRWxlbWVudCk7CiAgICAJCQl9KTsKICAgIAkJfSkKICAgIAkJLmNhdGNoKCgpID0+IHsKICAgIAkJCXNob3dUb2FzdCgnRmFpbGVkIHRvIGZldGNoIHJlc3VsdHMuJyk7CiAgICAJCX0pOwogICAgICAgIH0KICAgIAogICAgICAgIGZ1bmN0aW9uIGZldGNoSW5zdGFsbGVkUGFja2FnZXMoKSB7CiAgICAgICAgICAgIGZldGNoKGFwaUJhc2UsIHsKICAgICAgICAgICAgICAgIG1ldGhvZDogJ1BPU1QnLAogICAgICAgICAgICAgICAgaGVhZGVyczogewogICAgICAgICAgICAgICAgICAgICdDb250ZW50LVR5cGUnOiAnYXBwbGljYXRpb24veC13d3ctZm9ybS11cmxlbmNvZGVkJwogICAgICAgICAgICAgICAgfSwKICAgICAgICAgICAgICAgIGJvZHk6ICdhY3Rpb249aW5zdGFsbGVkJwogICAgICAgICAgICB9KQogICAgICAgICAgICAudGhlbihyZXNwb25zZSA9PiByZXNwb25zZS5qc29uKCkpCiAgICAgICAgICAgIC50aGVuKGRhdGEgPT4gewogICAgICAgICAgICAgICAgY29uc3QgaW5zdGFsbGVkQ29udGFpbmVyID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ2luc3RhbGxlZC1wYWNrYWdlcycpOwogICAgICAgICAgICAgICAgaW5zdGFsbGVkQ29udGFpbmVyLmlubmVySFRNTCA9ICcnOwogICAgICAgICAgICAgICAgZGF0YS5pbnN0YWxsZWQuZm9yRWFjaChpdGVtID0+IHsKICAgICAgICAgICAgICAgICAgICBjb25zdCBpdGVtRWxlbWVudCA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2RpdicpOwogICAgICAgICAgICAgICAgICAgIGl0ZW1FbGVtZW50LmNsYXNzTGlzdC5hZGQoJ2luc3RhbGxlZC1pdGVtJyk7CiAgICAgICAgICAgICAgICAgICAgaXRlbUVsZW1lbnQuaW5uZXJIVE1MID0gYAogICAgICAgICAgICAgICAgICAgICAgICA8c3Ryb25nPiR7aXRlbS5uYW1lfUAke2l0ZW0udmVyc2lvbn08L3N0cm9uZz4KICAgICAgICAgICAgICAgICAgICAgICAgPGRpdiBjbGFzcz0iaW5zdGFsbGVkLWl0ZW0tYnV0dG9ucyI+CiAgICAgICAgICAgICAgICAgICAgICAgICAgICA8YnV0dG9uIG9uY2xpY2s9Imluc3RhbGxQYWNrYWdlKCcke2l0ZW0ubmFtZX0nLCAnJHtpdGVtLnZlcnNpb259JywgdHJ1ZSkiPlJlaW5zdGFsbDwvYnV0dG9uPgogICAgICAgICAgICAgICAgICAgICAgICAgICAgPGJ1dHRvbiBvbmNsaWNrPSJ1bmluc3RhbGxQYWNrYWdlKCcke2l0ZW0ubmFtZX0nKSI+VW5pbnN0YWxsPC9idXR0b24+CiAgICAgICAgICAgICAgICAgICAgICAgIDwvZGl2PgogICAgICAgICAgICAgICAgICAgIGA7CiAgICAgICAgICAgICAgICAgICAgaW5zdGFsbGVkQ29udGFpbmVyLmFwcGVuZENoaWxkKGl0ZW1FbGVtZW50KTsKICAgICAgICAgICAgICAgIH0pOwogICAgICAgICAgICB9KQogICAgICAgICAgICAuY2F0Y2goKCkgPT4gewogICAgICAgICAgICAgICAgc2hvd1RvYXN0KCdGYWlsZWQgdG8gZmV0Y2ggaW5zdGFsbGVkIHBhY2thZ2VzLicpOwogICAgICAgICAgICB9KTsKICAgICAgICB9CiAgICAKICAgICAgICBmdW5jdGlvbiBmZXRjaFVwZGF0ZVBhY2thZ2VzKCkgewogICAgICAgICAgICBmZXRjaChhcGlCYXNlLCB7CiAgICAgICAgICAgICAgICBtZXRob2Q6ICdQT1NUJywKICAgICAgICAgICAgICAgIGhlYWRlcnM6IHsKICAgICAgICAgICAgICAgICAgICAnQ29udGVudC1UeXBlJzogJ2FwcGxpY2F0aW9uL3gtd3d3LWZvcm0tdXJsZW5jb2RlZCcKICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICBib2R5OiAnYWN0aW9uPXVwZGF0ZScKICAgICAgICAgICAgfSkKICAgICAgICAgICAgLnRoZW4ocmVzcG9uc2UgPT4gcmVzcG9uc2UuanNvbigpKQogICAgICAgICAgICAudGhlbihkYXRhID0+IHsKICAgICAgICAgICAgICAgIGNvbnN0IHVwZGF0ZUNvbnRhaW5lciA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCd1cGRhdGUtcGFja2FnZXMnKTsKICAgICAgICAgICAgICAgIHVwZGF0ZUNvbnRhaW5lci5pbm5lckhUTUwgPSAnJzsKICAgICAgICAgICAgICAgIGRhdGEudXBkYXRlcy5mb3JFYWNoKGl0ZW0gPT4gewogICAgICAgICAgICAgICAgICAgIGNvbnN0IGl0ZW1FbGVtZW50ID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnZGl2Jyk7CiAgICAgICAgICAgICAgICAgICAgaXRlbUVsZW1lbnQuY2xhc3NMaXN0LmFkZCgndXBkYXRlLWl0ZW0nKTsKICAgICAgICAgICAgICAgICAgICBpdGVtRWxlbWVudC5pbm5lckhUTUwgPSBgCiAgICAgICAgICAgICAgICAgICAgICAgIDxzdHJvbmc+JHtpdGVtLm5hbWV9QCR7aXRlbS5jdXJyZW50X3ZlcnNpb259IOKGkiAke2l0ZW0ubGF0ZXN0X3ZlcnNpb259PC9zdHJvbmc+CiAgICAgICAgICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9InVwZGF0ZS1pdGVtLWJ1dHRvbnMiPgogICAgICAgICAgICAgICAgICAgICAgICAgICAgPGJ1dHRvbiBvbmNsaWNrPSJpbnN0YWxsUGFja2FnZSgnJHtpdGVtLm5hbWV9JywgJyR7aXRlbS5sYXRlc3RfdmVyc2lvbn0nLCB0cnVlKSI+VXBkYXRlPC9idXR0b24+CiAgICAJCQkJCQk8YnV0dG9uIG9uY2xpY2s9Imluc3RhbGxQYWNrYWdlKCcke2l0ZW0ubmFtZX0nLCAnJHtpdGVtLmxhdGVzdF92ZXJzaW9ufScsIHRydWUpIj5SZWluc3RhbGw8L2J1dHRvbj4KICAgIAkJCQkJCTxidXR0b24gb25jbGljaz0idW5pbnN0YWxsUGFja2FnZSgnJHtpdGVtLm5hbWV9JykiPlVuaW5zdGFsbDwvYnV0dG9uPgogICAgICAgICAgICAgICAgICAgICAgICA8L2Rpdj4KICAgICAgICAgICAgICAgICAgICBgOwogICAgICAgICAgICAgICAgICAgIHVwZGF0ZUNvbnRhaW5lci5hcHBlbmRDaGlsZChpdGVtRWxlbWVudCk7CiAgICAgICAgICAgICAgICB9KTsKICAgICAgICAgICAgfSkKICAgICAgICAgICAgLmNhdGNoKCgpID0+IHsKICAgICAgICAgICAgICAgIHNob3dUb2FzdCgnRmFpbGVkIHRvIGZldGNoIHVwZGF0ZSBwYWNrYWdlcy4nKTsKICAgICAgICAgICAgfSk7CiAgICAgICAgfQogICAgCiAgICAgICAgZnVuY3Rpb24gc2hvd0RldGFpbHMobmFtZSwgdmVyc2lvbiwgZGVzY3JpcHRpb24pIHsKICAgIAkJc2hvd01vZGFsKCdkZXRhaWxzLW1vZGFsJywgYAogICAgCQkJPHA+PHN0cm9uZz5OYW1lOjwvc3Ryb25nPiAke25hbWV9PC9wPgogICAgCQkJPHA+PHN0cm9uZz5EZXNjcmlwdGlvbjo8L3N0cm9uZz4gJHtkZXNjcmlwdGlvbn08L3A+CiAgICAJCQk8cD48c3Ryb25nPlZlcnNpb246PC9zdHJvbmc+ICR7dmVyc2lvbn08L3A+CiAgICAJCWApOwogICAgICAgIH0KICAgIAogICAgICAgIGZ1bmN0aW9uIGluc3RhbGxQYWNrYWdlKG5hbWUsIHZlcnNpb24sIHJlaW5zdGFsbCA9IGZhbHNlKSB7CiAgICAJCWNvbnN0IGFjdGlvbiA9IHJlaW5zdGFsbCA/ICdyZWluc3RhbGwnIDogJ2luc3RhbGwnOwogICAgICAgICAgICBmZXRjaChhcGlCYXNlLCB7CiAgICAgICAgICAgICAgICBtZXRob2Q6ICdQT1NUJywKICAgICAgICAgICAgICAgIGhlYWRlcnM6IHsKICAgICAgICAgICAgICAgICAgICAnQ29udGVudC1UeXBlJzogJ2FwcGxpY2F0aW9uL3gtd3d3LWZvcm0tdXJsZW5jb2RlZCcKICAgICAgICAgICAgICAgIH0sCiAgICAgICAgICAgICAgICBib2R5OiBgYWN0aW9uPSR7YWN0aW9ufSZuYW1lPSR7ZW5jb2RlVVJJQ29tcG9uZW50KG5hbWUpfSZ2ZXJzaW9uPSR7ZW5jb2RlVVJJQ29tcG9uZW50KHZlcnNpb24pfWAKICAgICAgICAgICAgfSkKICAgICAgICAgICAgLnRoZW4ocmVzcG9uc2UgPT4gcmVzcG9uc2UuanNvbigpKQogICAgICAgICAgICAudGhlbihkYXRhID0+IHsKICAgICAgICAgICAgICAgIGlmIChkYXRhLnN1Y2Nlc3MpIHsKICAgICAgICAgICAgICAgICAgICBzaG93VG9hc3QoJ1BhY2thZ2UgaW5zdGFsbGVkIHN1Y2Nlc3NmdWxseS4nKTsKICAgICAgICAgICAgICAgICAgICBmZXRjaEluc3RhbGxlZFBhY2thZ2VzKCk7CiAgICAgICAgICAgICAgICB9IGVsc2UgewogICAgICAgICAgICAgICAgICAgIHNob3dUb2FzdCgnRmFpbGVkIHRvIGluc3RhbGwgcGFja2FnZS4nKTsKICAgICAgICAgICAgICAgIH0KICAgICAgICAgICAgfSkKICAgICAgICAgICAgLmNhdGNoKCgpID0+IHsKICAgICAgICAgICAgICAgIHNob3dUb2FzdCgnRmFpbGVkIHRvIGluc3RhbGwgcGFja2FnZS4nKTsKICAgICAgICAgICAgfSk7CiAgICAgICAgfQogICAgCiAgICAJZnVuY3Rpb24gc2hvd0F2YWlsYWJsZVZlcnNpb25zKHBhY2thZ2VOYW1lKSB7CiAgICAJCWZldGNoKGFwaUJhc2UsIHsKICAgIAkJCW1ldGhvZDogJ1BPU1QnLAogICAgCQkJaGVhZGVyczogewogICAgCQkJCSdDb250ZW50LVR5cGUnOiAnYXBwbGljYXRpb24veC13d3ctZm9ybS11cmxlbmNvZGVkJwogICAgCQkJfSwKICAgIAkJCWJvZHk6IGBhY3Rpb249dmVyc2lvbnMmbmFtZT0ke2VuY29kZVVSSUNvbXBvbmVudChwYWNrYWdlTmFtZSl9YAogICAgCQl9KQogICAgCQkudGhlbihyZXNwb25zZSA9PiByZXNwb25zZS5qc29uKCkpCiAgICAJCS50aGVuKGRhdGEgPT4gewogICAgCQkJaWYgKGRhdGEudmVyc2lvbnMpIHsKICAgIAkJCQljb25zdCB2ZXJzaW9uQ29udGVudCA9IGRhdGEudmVyc2lvbnMubWFwKHZlcnNpb24gPT4gYAogICAgCQkJCQk8ZGl2PgogICAgCQkJCQkJPHNwYW4+JHt2ZXJzaW9ufTwvc3Bhbj4KICAgIAkJCQkJCTxidXR0b24gb25jbGljaz0iaW5zdGFsbFBhY2thZ2UoJyR7cGFja2FnZU5hbWV9JywgJyR7dmVyc2lvbn0nLCB0cnVlKSI+SW5zdGFsbDwvYnV0dG9uPgogICAgCQkJCQk8L2Rpdj4KICAgIAkJCQlgKS5qb2luKCcnKTsKICAgIAogICAgCQkJCXNob3dNb2RhbCgndmVyc2lvbnMtbW9kYWwnLCBgCiAgICAJCQkJCTxoMj5BdmFpbGFibGUgVmVyc2lvbnMgZm9yICR7cGFja2FnZU5hbWV9PC9oMj4KICAgIAkJCQkJJHt2ZXJzaW9uQ29udGVudH0KICAgIAkJCQkJPGJ1dHRvbiBvbmNsaWNrPSJjbG9zZU1vZGFsKCd2ZXJzaW9ucy1tb2RhbCcpIj5DbG9zZTwvYnV0dG9uPgogICAgCQkJCWApOwogICAgCQkJfSBlbHNlIHsKICAgIAkJCQlzaG93VG9hc3QoJ05vIHZlcnNpb25zIGF2YWlsYWJsZSBmb3IgdGhpcyBwYWNrYWdlLicpOwogICAgCQkJfQogICAgCQl9KQogICAgCQkuY2F0Y2goKCkgPT4gewogICAgCQkJc2hvd1RvYXN0KCdGYWlsZWQgdG8gZmV0Y2ggYXZhaWxhYmxlIHZlcnNpb25zLicpOwogICAgCQl9KTsKICAgIAl9CiAgICAKICAgICAgICBmdW5jdGlvbiB1bmluc3RhbGxQYWNrYWdlKG5hbWUpIHsKICAgICAgICAgICAgZmV0Y2goYXBpQmFzZSwgewogICAgICAgICAgICAgICAgbWV0aG9kOiAnUE9TVCcsCiAgICAgICAgICAgICAgICBoZWFkZXJzOiB7CiAgICAgICAgICAgICAgICAgICAgJ0NvbnRlbnQtVHlwZSc6ICdhcHBsaWNhdGlvbi94LXd3dy1mb3JtLXVybGVuY29kZWQnCiAgICAgICAgICAgICAgICB9LAogICAgICAgICAgICAgICAgYm9keTogYGFjdGlvbj11bmluc3RhbGwmbmFtZT0ke2VuY29kZVVSSUNvbXBvbmVudChuYW1lKX1gCiAgICAgICAgICAgIH0pCiAgICAgICAgICAgIC50aGVuKHJlc3BvbnNlID0+IHJlc3BvbnNlLmpzb24oKSkKICAgICAgICAgICAgLnRoZW4oZGF0YSA9PiB7CiAgICAgICAgICAgICAgICBpZiAoZGF0YSkgewogICAgICAgICAgICAgICAgICAgIHNob3dUb2FzdCgnUGFja2FnZSB1bmluc3RhbGxlZCBzdWNjZXNzZnVsbHkuJyk7CiAgICAgICAgICAgICAgICAgICAgZmV0Y2hJbnN0YWxsZWRQYWNrYWdlcygpOwogICAgICAgICAgICAgICAgfSBlbHNlIHsKICAgICAgICAgICAgICAgICAgICBzaG93VG9hc3QoJ0ZhaWxlZCB0byB1bmluc3RhbGwgcGFja2FnZS4nKTsKICAgICAgICAgICAgICAgIH0KICAgICAgICAgICAgfSkKICAgICAgICAgICAgLmNhdGNoKCgpID0+IHsKICAgICAgICAgICAgICAgIHNob3dUb2FzdCgnRmFpbGVkIHRvIHVuaW5zdGFsbCBwYWNrYWdlLicpOwogICAgICAgICAgICB9KTsKICAgICAgICB9CiAgICAKICAgICAgICBmdW5jdGlvbiBpbml0aWFsaXplKCkgewogICAgICAgICAgICB0YWJzLmZvckVhY2godGFiID0+IHsKICAgICAgICAgICAgICAgIHRhYi5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsICgpID0+IHsKICAgICAgICAgICAgICAgICAgICBzaG93VGFiKHRhYi5nZXRBdHRyaWJ1dGUoJ2RhdGEtdGFiJykpOwogICAgICAgICAgICAgICAgfSk7CiAgICAgICAgICAgIH0pOwogICAgCiAgICAgICAgICAgIGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdzZWFyY2gtaW5wdXQnKS5hZGRFdmVudExpc3RlbmVyKCdpbnB1dCcsIChlKSA9PiB7CiAgICAgICAgICAgICAgICBmZXRjaFJlc3VsdHMoZS50YXJnZXQudmFsdWUpOwogICAgICAgICAgICB9KTsKICAgIAogICAgCQlmZXRjaERlZnVsdCgpOwogICAgICAgICAgICBmZXRjaEluc3RhbGxlZFBhY2thZ2VzKCk7CiAgICAgICAgICAgIGZldGNoVXBkYXRlUGFja2FnZXMoKTsKICAgICAgICB9CiAgICAKICAgICAgICBpbml0aWFsaXplKCk7CiAgICA8L3NjcmlwdD4KPC9ib2R5PgoKPC9odG1sPg==";
        $decodedText2 = base64_decode($encodedText2);
        if (class_exists('PHRO') && is_callable(['PHRO', 'getToken'])) {
            $csrfToken = json_encode(
                PHRO::getToken(),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
            );
            $csrfBootstrap = "';\n"
                . "        const phcdNativeFetch = window.fetch.bind(window);\n"
                . "        window.fetch = (input, init = {}) => {\n"
                . "            const headers = new Headers(init.headers || {});\n"
                . "            headers.set('X-CSRF-Token', {$csrfToken});\n"
                . "            return phcdNativeFetch(input, { ...init, headers });\n"
                . "        };\n";
            $decodedText2 = $csrfBootstrap . substr($decodedText2, 2);
        }
        $uiEnhancement = <<<'HTML'
<style id="phcd-enhancements">
    .phcd-toolbar{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin:-8px auto 18px;max-width:1200px}
    .phcd-toolbar button{border:1px solid #4a8cfd;border-radius:7px;padding:8px 13px;background:#273e67;color:#fff;cursor:pointer;font-weight:600}
    .phcd-toolbar button:hover{background:#376fd6}.phcd-toolbar button:disabled{opacity:.55;cursor:wait}
    .phcd-status{min-height:20px;color:#9fb7d9;font-size:13px;flex:1 1 180px;text-align:center}
    .phcd-status[data-state="error"]{color:#fda4af}.phcd-status[data-state="ok"]{color:#86efac}
    .result-item,.installed-item,.update-item{min-width:0}.result-item strong,.installed-item strong,.update-item strong{overflow-wrap:anywhere}
    .result-item-buttons,.installed-item-buttons,.update-item-buttons{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    @media(max-width:640px){body{padding:12px}.phcd-toolbar{align-items:stretch}.phcd-toolbar button,.phcd-status{width:100%;flex-basis:100%}}
</style>
<script>
(() => {
    const heading = document.querySelector('body > h1');
    if (!heading || document.querySelector('.phcd-toolbar')) return;
    const toolbar = document.createElement('div');
    toolbar.className = 'phcd-toolbar';
    toolbar.innerHTML = '<button type="button" data-phcd-action="installed">Refresh installed</button>'
        + '<button type="button" data-phcd-action="updates">Check updates</button>'
        + '<span class="phcd-status" role="status" aria-live="polite" data-state="ok">Ready</span>';
    heading.insertAdjacentElement('afterend', toolbar);
    const status = toolbar.querySelector('.phcd-status');
    const setStatus = (message, state = 'ok') => { status.textContent = message; status.dataset.state = state; };
    toolbar.addEventListener('click', event => {
        const button = event.target.closest('button[data-phcd-action]');
        if (!button) return;
        button.disabled = true;
        setStatus(button.dataset.phcdAction === 'updates' ? 'Checking updates…' : 'Refreshing installed packages…');
        try {
            if (button.dataset.phcdAction === 'updates' && typeof fetchUpdatePackages === 'function') fetchUpdatePackages();
            else if (button.dataset.phcdAction === 'installed' && typeof fetchInstalledPackages === 'function') fetchInstalledPackages();
            else setStatus('Action is not available.', 'error');
        } catch (_) { setStatus('Request could not be started.', 'error'); }
        window.setTimeout(() => { button.disabled = false; if (status.dataset.state !== 'error') setStatus('Ready'); }, 900);
    });
    const search = document.getElementById('search-input');
    if (search) search.addEventListener('keydown', event => {
        if (event.key === 'Enter' && typeof fetchResults === 'function') fetchResults(search.value.trim());
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') document.querySelectorAll('.modal').forEach(modal => { modal.style.display = 'none'; });
    });
    window.addEventListener('unhandledrejection', () => setStatus('A background request failed.', 'error'));
})();
</script>
HTML;
        $decodedText2 = str_replace('</body>', $uiEnhancement . '</body>', $decodedText2);
        print $decodedText1.PHRO::root().self::$state.$decodedText2;
    }

    /**
     * Handles incoming POST requests related to CDN operations.
     * It processes actions such as searching, installing, uninstalling, and listing packages.
     */
    public static function handleRequest() {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $action = strtolower(trim((string) $_POST['action']));
            $response = [];

            switch ($action) {
                case 'search':
                    if (isset($_POST['query'])) {
                        $response = self::searchPackages($_POST['query']);
                    }
                    break;
                case 'versions':
                    if (isset($_POST['name'])) {
                        $response = self::getPackageVersions($_POST['name']);
                    }
                    break;
                case 'install':
                    if (isset($_POST['name']) && isset($_POST['version'])) {
                        $response = self::installPackage($_POST['name'], $_POST['version'], false);
                    }
                    break;
                case 'reinstall':
                    if (isset($_POST['name']) && isset($_POST['version'])) {
                        $response = self::installPackage($_POST['name'], $_POST['version'], true);
                    }
                    break;
                case 'uninstall':
                    if (isset($_POST['name'])) {
                        $response = self::uninstallPackage($_POST['name']);
                    }
                    break;
                case 'installed':
                    $response = self::listInstalledPackages();
                    break;
                case 'update':
                    $response = self::listUpdates();
                    break;
                default:
                    http_response_code(400);
                    $response = ['error' => 'Invalid action'];
                    break;
            }

            echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Invalid request method']);
        }
    }

    /**
     * Searches for packages on the CDNJS API based on the provided query.
     * 
     * @param string $query The search term to find matching packages.
     */
    private static function searchPackages($query) {
        $searchUrl = empty($query) || strlen($query) < 3 
            ? self::$apiBaseUrl . "?fields=name,author,description,version,repository&limit=100"
            : self::$apiBaseUrl . "?search=" . urlencode($query) . '&fields=name,author,description,version,repository';

        $response = json_decode((string) self::download($searchUrl), true);
        $results = [];

        foreach (($response['results'] ?? []) as $pkg) {
            $results[] = [
                'name' => $pkg['name'],
                'author' => $pkg['author'],
                'description' => $pkg['description'],
                'repository' => $pkg['repository'],
                'latest_version' => $pkg['version'],
                'is_installed' => self::checkIfInstalled($pkg['name'], $pkg['version'])
            ];
        }
        return ['results' => $results];
    }

    /**
     * Retrieves all available versions for a specific package.
     *
     * @param string $name The name of the package to retrieve versions for.
     */
    private static function getPackageVersions($name) {
        $packageUrl = self::$apiBaseUrl . '/' . urlencode($name) . '?fields=name,versions';
        $packageData = json_decode((string) self::download($packageUrl), true);

        if (isset($packageData['versions']) && is_array($packageData['versions'])) {
            $versions = $packageData['versions'];
            usort($versions, 'version_compare');
            $versions = array_reverse($versions);
            return ['versions' => $versions];
        } else {
            return ['error' => 'Package not found or no versions available'];
        }
    }

    /**
     * Checks if a specific version of a package is already installed.
     *
     * @param string $name The package name.
     * @param string $version The package version.
     * @return bool Returns true if the package version is installed, false otherwise.
     */
    private static function checkIfInstalled($name, $version) {
        $folderName = strtolower(str_replace(' ', '-', $name)) . '@' . $version;
        return is_dir(self::$jsPath . $folderName) || is_dir(self::$cssPath . $folderName);
    }

    /**
     * Installs a specific version of a package by downloading its assets from the CDNJS API.
     *
     * @param string $name The package name.
     * @param string $version The version of the package to install.
     * @param bool $reinstall Whether to reinstall the package (uninstall and then install again).
     */
    private static function installPackage($name, $version, $reinstall = false) {
        $name = self::validatePackageName($name);
        if ($name === null || !is_string($version) || !preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][A-Za-z0-9.-]+)?$/', $version)) {
            return ['error' => 'Invalid package name or version.'];
        }

        $packageUrl = self::$apiBaseUrl . '/' . urlencode($name);
        $packageData = json_decode((string) self::download($packageUrl), true);

        if (!$packageData) {
            return ["error" => "Failed to fetch package data from CDNJS API for: $name"];
        }
        if (!isset($packageData['assets'])) {
            return ["error" => "No assets found for package: $name"];
        }

        foreach ($packageData['assets'] as $asset) {
            if (($asset['version'] ?? null) !== $version || !is_array($asset['files'] ?? null)) continue;

            $folderName = strtolower(str_replace(' ', '-', $name)) . '@' . $version;
            $token = bin2hex(random_bytes(8));
            $roots = [
                'css' => rtrim(self::$cssPath, '/\\') . DIRECTORY_SEPARATOR,
                'js' => rtrim(self::$jsPath, '/\\') . DIRECTORY_SEPARATOR,
            ];
            $stages = $targets = $backups = $seen = [];

            try {
                foreach ($roots as $type => $root) {
                    if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
                        throw new \RuntimeException("Failed to create package root: {$root}");
                    }
                    $targets[$type] = $root . $folderName;
                    $stages[$type] = $root . '.phcd-stage-' . $folderName . '-' . $token;
                    $backups[$type] = $root . '.phcd-backup-' . $folderName . '-' . $token;
                }

                foreach ($asset['files'] as $file) {
                    if (!is_string($file) || $file === '' || str_contains($file, "\0")) continue;
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (!in_array($extension, ['css', 'js'], true)) continue;
                    $type = $extension;
                    $baseName = basename(str_replace('\\', '/', $file));
                    if ($baseName === '' || in_array($baseName, ['.', '..'], true)) continue;
                    if (isset($seen[$type][$baseName])) {
                        throw new \RuntimeException("Package contains duplicate flattened filename: {$baseName}");
                    }
                    $seen[$type][$baseName] = true;
                    if (!is_dir($stages[$type]) && !mkdir($stages[$type], 0755, true) && !is_dir($stages[$type])) {
                        throw new \RuntimeException("Failed to create package staging directory.");
                    }

                    $encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim(str_replace('\\', '/', $file), '/'))));
                    $fileUrl = 'https://cdnjs.cloudflare.com/ajax/libs/' . rawurlencode($name) . '/' . rawurlencode($version) . '/' . $encodedPath;
                    $contents = self::download($fileUrl);
                    if ($contents === false) {
                        throw new \RuntimeException("Failed to download package asset: {$baseName}");
                    }
                    if (file_put_contents($stages[$type] . DIRECTORY_SEPARATOR . $baseName, $contents, LOCK_EX) === false) {
                        throw new \RuntimeException("Failed to stage package asset: {$baseName}");
                    }
                }

                if (!$seen) throw new \RuntimeException('The selected package version has no installable assets.');

                $committed = [];
                foreach (array_keys($seen) as $type) {
                    if (is_dir($targets[$type]) && !rename($targets[$type], $backups[$type])) {
                        throw new \RuntimeException("Failed to create package rollback point.");
                    }
                    if (!rename($stages[$type], $targets[$type])) {
                        if (is_dir($backups[$type])) @rename($backups[$type], $targets[$type]);
                        throw new \RuntimeException("Failed to activate staged package.");
                    }
                    $committed[] = $type;
                }

                foreach ($committed as $type) {
                    if (is_dir($backups[$type])) self::deleteDir($backups[$type], $roots[$type]);
                }
                return $reinstall
                    ? ["message" => "Reinstalled package: $name@$version"]
                    : ["message" => "Installed package: $name@$version"];
            } catch (\Throwable $error) {
                foreach (array_keys($roots) as $type) {
                    if (isset($stages[$type]) && is_dir($stages[$type])) self::deleteDir($stages[$type], $roots[$type]);
                    if (isset($backups[$type]) && is_dir($backups[$type])) {
                        if (isset($targets[$type]) && is_dir($targets[$type])) self::deleteDir($targets[$type], $roots[$type]);
                        @rename($backups[$type], $targets[$type]);
                    } elseif (in_array($type, $committed ?? [], true) && isset($targets[$type]) && is_dir($targets[$type])) {
                        self::deleteDir($targets[$type], $roots[$type]);
                    }
                }
                return ['error' => $error->getMessage()];
            }
        }

        return ["error" => "Version $version not found for package: $name"];
    }

    /**
     * Lists all installed packages by scanning the CSS and JS directories.
     * 
     * @return void Outputs the list of installed packages in JSON format.
     */
    private static function listInstalledPackages() {
        $installedPackages = [];
        $jsDirs = glob(rtrim(self::$jsPath, '/\\') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        $cssDirs = glob(rtrim(self::$cssPath, '/\\') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];

        $allDirs = array_merge($jsDirs, $cssDirs);

        foreach ($allDirs as $dir) {
            $dirName = basename($dir);
            if (preg_match('/^(.+)@([0-9]+(?:\.[0-9]+){0,3}(?:[-+][A-Za-z0-9.-]+)?)$/', $dirName, $matches)) {
                $packageName = $matches[1];
                $packageVersion = $matches[2];

                $exists = false;
                foreach ($installedPackages as $package) {
                    if ($package['name'] === $packageName && $package['version'] === $packageVersion) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $installedPackages[] = [
                        'name' => $packageName,
                        'version' => $packageVersion,
                    ];
                }
            }
        }

        return ['installed' => $installedPackages];
    }

    /**
     * Lists available updates for installed packages by comparing current versions with the latest ones.
     * 
     * @return array Returns an array containing the list of packages with available updates.
     */
    private static function listUpdates() {
        $installedPackages = [];
        $jsDirs = glob(rtrim(self::$jsPath, '/\\') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        $cssDirs = glob(rtrim(self::$cssPath, '/\\') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];

        $allDirs = array_merge($jsDirs, $cssDirs);
        $packageCache = [];
        $seenPackages = [];

        foreach ($allDirs as $dir) {
            $dirName = basename($dir);
            if (preg_match('/^(.+)@([0-9]+(?:\.[0-9]+){0,3}(?:[-+][A-Za-z0-9.-]+)?)$/', $dirName, $matches)) {
                $packageName = $matches[1];
                $installedVersion = $matches[2];
                if (isset($seenPackages[$packageName])) {
                    continue;
                }
                $seenPackages[$packageName] = true;

                if (!isset($packageCache[$packageName])) {
                    $packageUrl = self::$apiBaseUrl . '/' . urlencode($packageName);
                    $response = self::download($packageUrl);

                    if ($response === false) {
                        continue;
                    }

                    $packageData = json_decode($response, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        continue;
                    }

                    $packageCache[$packageName] = $packageData;
                } else {
                    $packageData = $packageCache[$packageName];
                }

                if (isset($packageData['version']) && version_compare($packageData['version'], $installedVersion, '>')) {
                    $installedPackages[] = [
                        'name' => $packageName,
                        'current_version' => $installedVersion,
                        'latest_version' => $packageData['version'],
                    ];
                }
            }
        }

        return ['updates' => $installedPackages];
    }

    private static function download(string $url, int $maxBytes = 5242880): string|false {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') return false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) return false;
            $body = '';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'MyStack-PHCD/1.0',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            return $ok !== false && $error === '' && $status >= 200 && $status < 300 ? $body : false;
        }
        $context = stream_context_create([
            'http' => ['timeout' => 20, 'ignore_errors' => true, 'header' => "User-Agent: MyStack-PHCD/1.0\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        if ($body === false || strlen($body) > $maxBytes) return false;
        $status = 0;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) $status = (int) $match[1];
        return $status >= 200 && $status < 300 ? $body : false;
    }

    /**
     * Uninstalls a package by removing its corresponding CSS and JS directories.
     *
     * @param string $name The name of the package to uninstall.
     */
    private static function uninstallPackage($name) {
        $name = self::validatePackageName($name);
        if ($name === null) {
            return ['error' => 'Invalid package name.'];
        }

        $targetDirJs = rtrim(self::$jsPath, '/\\') . DIRECTORY_SEPARATOR . strtolower($name) . '@*';
        $jsDirs = glob($targetDirJs, GLOB_ONLYDIR);
        foreach ($jsDirs as $dir) {
            self::deleteDir($dir, self::$jsPath);
        }

        $targetDirCss = rtrim(self::$cssPath, '/\\') . DIRECTORY_SEPARATOR . strtolower($name) . '@*';
        $cssDirs = glob($targetDirCss, GLOB_ONLYDIR);
        foreach ($cssDirs as $dir) {
            self::deleteDir($dir, self::$cssPath);
        }

        return ['message' => "Uninstalled package: $name"];
    }

    /**
     * Deletes a directory and all its contents.
     *
     * @param string $dir The directory to delete.
     * @return bool Returns true if the directory was successfully deleted, false otherwise.
     */
    private static function deleteDir($dirPath, $allowedRoot) {
        $root = realpath($allowedRoot);
        $resolved = realpath($dirPath);
        if ($root === false || $resolved === false || !is_dir($resolved) || is_link($dirPath)) {
            return false;
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $resolvedNormalized = rtrim(str_replace('\\', '/', $resolved), '/');
        if (!str_starts_with($resolvedNormalized . '/', $rootPrefix) || $resolvedNormalized === rtrim($rootPrefix, '/')) {
            return false;
        }

        $files = array_diff(scandir($resolved) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $filePath = $resolved . DIRECTORY_SEPARATOR . $file;
            if (is_link($filePath)) {
                @unlink($filePath);
            } elseif (is_dir($filePath)) {
                if (!self::deleteDir($filePath, $root)) {
                    return false;
                }
            } elseif (!is_file($filePath) || !@unlink($filePath)) {
                return false;
            }
        }
        return @rmdir($resolved);
    }

    private static function validatePackageName($name): ?string {
        if (!is_string($name)) {
            return null;
        }
        $name = strtolower(trim($name));
        return preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $name) ? $name : null;
    }

    private static function requireAccess(bool $json): bool {
        $allowed = false;
        if (is_callable(self::$authorize)) {
            try {
                $allowed = (bool) call_user_func(self::$authorize);
            } catch (\Throwable $error) {
                $allowed = false;
            }
        } else {
            $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $allowed = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
        }

        if ($allowed) {
            return true;
        }

        http_response_code(403);
        if (!headers_sent()) {
            header('Cache-Control: no-store');
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
            }
        }
        echo $json
            ? json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_SLASHES)
            : 'Forbidden';
        return false;
    }

    /**
     * Retrieves a list of files for specified packages based on their type (JavaScript or CSS).
     *
     * This method checks the installed packages and returns the list of files that match the specified
     * package names, file types (JavaScript or CSS), and filters. It supports single and multiple
     * values for each parameter, as well as boolean values for broad filtering.
     *
     * @param string|array|bool $package A single package name, a comma-separated list of package names, 
     *                                   an array of package names, or a boolean value. 
     *                                   - If string and no comma, retrieves files for the single package.
     *                                   - If string with a comma, retrieves files for the specified packages.
     *                                   - If empty string, retrieves files for all installed packages.
     *                                   - If true, retrieves files for all packages without filtering.
     *                                   - If false, returns an empty result.
     * @param string|array|bool $type The type of files to retrieve. Can be 'js' for JavaScript files, 'css' for CSS files,
     *                                or a boolean value.
     *                                - If string and no comma, retrieves files for the specified type.
     *                                - If string with a comma, retrieves files for the specified types.
     *                                - If empty string, retrieves files of all types.
     *                                - If true, retrieves files of all types.
     *                                - If false, returns an empty result.
     * @param string|array|bool $skipPKG Optional. A single package name, a comma-separated list of package names to skip,
     *                                   an array of package names to skip, or a boolean value.
     *                                   - If string and no comma, skips the specified package.
     *                                   - If string with a comma, skips the specified packages.
     *                                   - If empty string, skips no packages.
     *                                   - If true, skips no packages.
     *                                   - If false, skips all packages.
     * @param string|array|bool $skipFILE Optional. A single file name, a comma-separated list of file names to skip,
     *                                    an array of file names to skip, or a boolean value.
     *                                    - If string and no comma, skips the specified file.
     *                                    - If string with a comma, skips the specified files.
     *                                    - If empty string, skips no files.
     *                                    - If true, skips no files.
     *                                    - If false, skips all files.
     * @return array Returns the list of installed file paths relative to the "/src" directory
     *               for the specified packages and types.
     */
    public static function get($package = true, $type = true, $skipPKG = true, $skipFILE = true) {
        $results = [];

        $normalize = static function ($value, array $defaults = []) {
            if (is_bool($value)) return $value ? $defaults : null;
            $values = is_array($value) ? $value : explode(',', (string) $value);
            return array_values(array_filter(array_map(static fn($item) => trim((string) $item), $values), static fn($item) => $item !== ''));
        };
        $packages = $normalize($package);
        $types = $normalize($type, ['js', 'css']);
        $skipPKGs = $normalize($skipPKG);
        $skipFILES = $normalize($skipFILE);

        if ($packages === null || $types === null || $skipPKGs === null || $skipFILES === null) {
            return json_encode(['files' => $results]);
        }

        $installedPackages = self::listInstalledPackages()['installed'];
        if (empty($packages) && $package !== false) {
            $packages = array_column($installedPackages, 'name');
        }

        foreach ($packages as $pkg) {
            $packageParts = explode('@', (string) $pkg, 2);
            $pkgName = strtolower(trim($packageParts[0]));
            $requestedVersion = $packageParts[1] ?? null;

            if (in_array($pkgName, array_map('strtolower', $skipPKGs), true)) {
                continue;
            }

            $found = false;

            foreach ($installedPackages as $installed) {
                if ($installed['name'] === $pkgName && ($requestedVersion === null || $installed['version'] === $requestedVersion)) {
                    $found = true;
                    break;
                }
            }

            if ($found) {
                $folderName = strtolower(str_replace(' ', '-', $pkgName)) . '@' . $installed['version'];

                foreach ($types as $ext) {
                    $dirPath = ($ext === 'css') ? self::$cssPath . $folderName : self::$jsPath . $folderName;

                    if (is_dir($dirPath)) {
                        $files = glob($dirPath . '/*.' . $ext);
                        foreach ($files as $file) {
                            $fileName = basename($file);
                            if (!in_array($fileName, $skipFILES)) {
                            $projectRoot = dirname(__DIR__);
                            $relativePath = '/' . trim(str_replace('\\', '/', str_replace($projectRoot, '', $file)), '/');
                            $results[] = $relativePath;
                            }
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Generates HTML <link> or <script> tags for the specified packages and file types.
     *
     * @param string|array|bool $package A single package name, a comma-separated list of package names, 
     *                                   an array of package names, or a boolean value. 
     *                                   - If string and no comma, retrieves files for the single package.
     *                                   - If string with a comma, retrieves files for the specified packages.
     *                                   - If empty string, retrieves files for all installed packages.
     *                                   - If true, retrieves files for all packages without filtering.
     *                                   - If false, returns an empty result.
     * @param string|array|bool $type The type of files to retrieve. Can be 'js' for JavaScript files, 'css' for CSS files,
     *                                or a boolean value.
     *                                - If string and no comma, retrieves files for the specified type.
     *                                - If string with a comma, retrieves files for the specified types.
     *                                - If empty string, retrieves files of all types.
     *                                - If true, retrieves files of all types.
     *                                - If false, returns an empty result.
     * @param string|array|bool $skipPKG Optional. A single package name, a comma-separated list of package names to skip,
     *                                   an array of package names to skip, or a boolean value.
     *                                   - If string and no comma, skips the specified package.
     *                                   - If string with a comma, skips the specified packages.
     *                                   - If empty string, skips no packages.
     *                                   - If true, skips no packages.
     *                                   - If false, skips all packages.
     * @param string|array|bool $skipFILE Optional. A single file name, a comma-separated list of file names to skip,
     *                                    an array of file names to skip, or a boolean value.
     *                                    - If string and no comma, skips the specified file.
     *                                    - If string with a comma, skips the specified files.
     *                                    - If empty string, skips no files.
     *                                    - If true, skips no files.
     *                                    - If false, skips all files.
     * @param bool $defer Optional. Whether to add the `defer` attribute to JavaScript files. Default is false.
     * @return string Returns a string containing the generated HTML <link> or <script> tags.
     */
    public static function use($package = true, $type = true, $skipPKG = true, $skipFILE = true, $defer = false) {
        $files = self::get($package, $type, $skipPKG, $skipFILE);
        if (!is_array($files)) {
            $decoded = json_decode((string) $files, true);
            $files = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
        }
        $html = '';
        foreach ($files as $filePath) {
            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            $filePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $filePath);
            if ($ext === 'css') {
                $html .= '<link rel="stylesheet" href="' . htmlspecialchars($filePath) . '">' . PHP_EOL;
            } elseif ($ext === 'js') {
                $html .= '<script src="' . htmlspecialchars($filePath) . '"' . ($defer ? ' defer' : '') . '></script>' . PHP_EOL;
            }
        }
        return $html;
    }
}
?>
