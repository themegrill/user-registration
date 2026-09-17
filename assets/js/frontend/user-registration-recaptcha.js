
var originalGrecaptcha = null,
	originalHcaptcha = null,
	originalTurnstile = null,
	isolatedGrecaptcha = null,
	isolatedGrecaptcha2 = null;

var createIsolatedCaptchaObject = function(type) {
	switch(type) {
		case 'hcaptcha':
			// For hCaptcha, always use dedicated loader to avoid conflicts
			return createDedicatedHcaptchaLoader();
		case 'grecaptcha':
		case 'recaptcha':
			// For reCAPTCHA, always use dedicated loader to avoid conflicts
			return createDedicatedRecaptchaLoader();
		case 'turnstile':
			return window.turnstile || originalTurnstile;
		default:
			return null;
	}
};

// Create dedicated hCaptcha loader that loads AFTER reCAPTCHA
var createDedicatedHcaptchaLoader = function() {
	return {
		render: function(element, options) {
			// Convert string ID to DOM element if needed
			if (typeof element === 'string') {
				element = document.getElementById(element);
			}

			if (!element || !element.nodeType) {
				return 'hcaptcha-error';
			}

			// Clear element
			element.innerHTML = '';

			// Create placeholder
			var placeholder = document.createElement('div');
			placeholder.innerHTML = '<div style="display: flex; justify-content: center; align-items: center; padding: 10px; width: 100%;"><div class="ur-front-spinner" ></div></div>';
			element.appendChild(placeholder);

			// ALWAYS delay hCaptcha to ensure reCAPTCHA loads first
			setTimeout(function() {
				// Load hCaptcha with unique callback
				var uniqueId = 'dedicated_hcaptcha_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
				var callbackName = 'onloadDedicatedHcaptcha_' + uniqueId;

				window[callbackName] = function() {
					if (window.hcaptcha && window.hcaptcha.render) {
						element.innerHTML = '';
						var widgetId = window.hcaptcha.render(element, options);
						delete window[callbackName];
						return widgetId;
					}
				};

				var script = document.createElement('script');
				script.src = 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=' + callbackName;
				script.onload = function() {
					setTimeout(function() {
						if (window.hcaptcha && window.hcaptcha.render && element) {
							element.innerHTML = '';
							window.hcaptcha.render(element, options);
						}
					}, 2000);
				};
				document.head.appendChild(script);
			}, 2000); // 2 second delay to ensure reCAPTCHA loads first

			return 'hcaptcha-loading';
		},
		reset: function(widgetId) {
			// widgetId is a string placeholder ('hcaptcha-loading') until the
			// real widget has rendered; resetting with it throws and aborts
			// submission, so only reset a real (numeric) widget id and never throw.
			try {
				if (window.hcaptcha && window.hcaptcha.reset && typeof widgetId !== 'string') {
					window.hcaptcha.reset(widgetId);
				}
			} catch (e) {}
		},
		execute: function(siteKey, options) {
			try {
				if (window.hcaptcha && window.hcaptcha.execute) {
					window.hcaptcha.execute(siteKey, options);
				}
			} catch (e) {}
		},
		getResponse: function(widgetId) {
			if (window.hcaptcha && window.hcaptcha.getResponse && typeof widgetId !== 'string') {
				return window.hcaptcha.getResponse(widgetId);
			}
			return '';
		}
	};
};

// Create dedicated reCAPTCHA loader that loads FIRST (no delay)
var createDedicatedRecaptchaLoader = function() {
	return {
		render: function(element, options) {
			// Convert string ID to DOM element if needed
			if (typeof element === 'string') {
				element = document.getElementById(element);
			}

			if (!element || !element.nodeType) {
				return 'recaptcha-error';
			}

			// Clear element
			element.innerHTML = '';

			// Create placeholder
			var placeholder = document.createElement('div');
			placeholder.innerHTML = '<div style="display: flex; justify-content: center; align-items: center; padding: 10px; width: 100%;"><div class="ur-front-spinner" ></div></div>';
			element.appendChild(placeholder);

			// Load reCAPTCHA IMMEDIATELY (no delay) to ensure it loads first
			var uniqueId = 'dedicated_recaptcha_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
			var callbackName = 'onloadDedicatedRecaptcha_' + uniqueId;

			window[callbackName] = function() {
				if (window.grecaptcha && window.grecaptcha.render) {
					element.innerHTML = '';
					var widgetId = window.grecaptcha.render(element, options);
					delete window[callbackName];
					return widgetId;
				}
			};

			var script = document.createElement('script');
			script.src = 'https://www.google.com/recaptcha/api.js?render=explicit&callback=' + callbackName;
			script.onload = function() {
				setTimeout(function() {
					if (window.grecaptcha && window.grecaptcha.render && element) {
						element.innerHTML = '';
						window.grecaptcha.render(element, options);
					}
				}, 2000);
			};
			document.head.appendChild(script);

			return 'recaptcha-loading';
		},
		reset: function(widgetId) {
			// Skip the 'recaptcha-loading' placeholder id and never throw, so a
			// captcha reset can't abort form submission.
			try {
				if (window.grecaptcha && window.grecaptcha.reset && typeof widgetId !== 'string') {
					window.grecaptcha.reset(widgetId);
				}
			} catch (e) {}
		},
		execute: function(siteKey, options) {
			try {
				if (window.grecaptcha && window.grecaptcha.execute) {
					window.grecaptcha.execute(siteKey, options);
				}
			} catch (e) {}
		},
		getResponse: function(widgetId) {
			if (window.grecaptcha && window.grecaptcha.getResponse && typeof widgetId !== 'string') {
				return window.grecaptcha.getResponse(widgetId);
			}
			return '';
		}
	};
};

var getSafeCaptchaObject = function(type) {
	var captchaObj = createIsolatedCaptchaObject(type);

	if (captchaObj && captchaObj.render) {
		return captchaObj;
	}

	// Fallback: if we can't get a proper object, create a simple one
	if (type === 'hcaptcha' || type === 'grecaptcha' || type === 'recaptcha') {
		return createFallbackCaptcha(type);
	}

	return null;
};

/**
 * Hold a form's submission until its captcha hands back a replacement token.
 *
 * reset() clears the response field synchronously while the widget re-issues
 * asynchronously, so without the hold the form stays submittable for a second
 * or two with nothing to post and the visitor is shown a captcha error they
 * did nothing to cause.
 *
 * @since 5.2.9
 *
 * @param {Object} $form jQuery object for the form to hold.
 */
var ur_hold_form_for_captcha = function ($form) {
	var apply_hold = function () {
		if (!$form.data("ur-captcha-pending")) {
			return;
		}

		$form
			.find(".ur-submit-button")
			.prop("disabled", true)
			.find("span")
			.addClass("ur-front-spinner");
	};

	$form.data("ur-captcha-pending", true);
	apply_hold();

	// The submit handler re-enables the button after this event, so re-apply the
	// hold once it has finished with it.
	window.setTimeout(apply_hold, 0);

	// Never strand the visitor behind a widget that never comes back.
	window.clearTimeout($form.data("ur-captcha-timer"));
	$form.data(
		"ur-captcha-timer",
		window.setTimeout(function () {
			ur_release_form_for_captcha($form);
		}, 15000)
	);
};

/**
 * Release a form held by ur_hold_form_for_captcha().
 *
 * @since 5.2.9
 *
 * @param {Object} $form jQuery object for the form to release.
 */
var ur_release_form_for_captcha = function ($form) {
	if (!$form || !$form.length || !$form.data("ur-captcha-pending")) {
		return;
	}

	window.clearTimeout($form.data("ur-captcha-timer"));
	$form.removeData("ur-captcha-pending").removeData("ur-captcha-timer");
	$form
		.find(".ur-submit-button")
		.prop("disabled", false)
		.find("span")
		.removeClass("ur-front-spinner");
};

/**
 * Build the Turnstile render parameters for a form.
 *
 * The callback is what releases the hold, so every render and rebuild has to go
 * through here rather than pass a bare object.
 *
 * @since 5.2.9
 *
 * @param {Object} config Localised captcha config with site_key and theme_mode.
 * @param {Object} $form  jQuery object for the form the widget belongs to.
 * @return {Object} Parameters for turnstile.render().
 */
var ur_get_turnstile_params = function (config, $form) {
	return {
		sitekey: config.site_key,
		theme: config.theme_mode,
		style: "transform:scale(0.77);-webkit-transform:scale(0.77);transform-origin:0 0;-webkit-transform-origin:0 0;",
		callback: function () {
			ur_release_form_for_captcha($form);
		}
	};
};

/**
 * Refresh the captcha widget belonging to a single form.
 *
 * The server verifies the token before it validates the fields, so a submission
 * rejected for a field error has already spent it and resending it is rejected
 * as a duplicate. The type is read from the form's own node because the config
 * globals are page wide while the captcha type is per form.
 *
 * @since 5.2.9
 *
 * @param {Object} $form jQuery object for the form to refresh.
 */
var ur_refresh_form_captcha = function ($form) {
	if (!$form || !$form.length) {
		return;
	}

	// The node id is hard coded in the template, so scope the lookup to this form.
	var $node = $form.find("[id='ur-recaptcha-node']").children().first();

	if (!$node.length) {
		return;
	}

	var is_login = $form.hasClass("login"),
		widget_id = $node.data("ur-widget-id"),
		response_name = "",
		captcha_reset = false;

	if ($node.hasClass("cf-turnstile")) {
		response_name = "cf-turnstile-response";

		// Turnstile is the only widget here that re-issues without the visitor
		// touching it, so it is the only one worth holding the form for.
		ur_hold_form_for_captcha($form);

		if ("undefined" === typeof widget_id) {
			widget_id = is_login
				? turnstile_login
				: turnstile_user_registration;
		}

		var turnstileObj = getSafeCaptchaObject("turnstile");

		// A Turnstile widget id is a string, so it must not be filtered out the
		// way the hCaptcha and reCAPTCHA placeholder ids are.
		if (widget_id && turnstileObj && turnstileObj.reset) {
			try {
				turnstileObj.reset(widget_id);
			} catch (e) {}

			// reset() clears the response synchronously and only logs when the
			// id no longer resolves, so a token still sitting there means the
			// call did nothing.
			captcha_reset =
				"" ===
				($form.find('[name="' + response_name + '"]').val() || "");
		}

		// The widget could not be reset, so rebuild it from scratch.
		if (!captcha_reset && turnstileObj && turnstileObj.render) {
			try {
				var render_params = $node.data("ur-render-params");

				if (
					"undefined" === typeof render_params &&
					"undefined" !== typeof ur_cloudflare_recaptcha_code
				) {
					render_params = ur_get_turnstile_params(
						ur_cloudflare_recaptcha_code,
						$form
					);
				}

				if (render_params) {
					if (widget_id && turnstileObj.remove) {
						turnstileObj.remove(widget_id);
					}

					// Turnstile keys widgets by container element and refuses to
					// build a second one for the same node, so swapping in a fresh
					// container is the only way to guarantee a new widget once the
					// stored id has stopped resolving.
					var $fresh = jQuery("<div></div>")
						.attr("id", $node.attr("id"))
						.attr("class", $node.attr("class"))
						.data("ur-render-params", render_params);

					$node.replaceWith($fresh);
					$node = $fresh;

					widget_id = turnstileObj.render($node[0], render_params);
					$node.data("ur-widget-id", widget_id);
					captcha_reset = true;
				}
			} catch (e) {}
		}
	} else if ($node.hasClass("g-recaptcha-hcaptcha")) {
		response_name = "h-captcha-response";

		if ("undefined" === typeof widget_id) {
			widget_id = is_login ? hcaptcha_login : hcaptcha_user_registration;
		}

		var hcaptchaObj = getSafeCaptchaObject("hcaptcha");

		if (hcaptchaObj && hcaptchaObj.reset) {
			hcaptchaObj.reset(widget_id);
			captcha_reset = "string" !== typeof widget_id;
		}
	} else if ($node.hasClass("g-recaptcha-v3")) {
		// v3 mints a token on request instead of holding a widget.
		request_recaptcha_token();

		return;
	} else if ($node.hasClass("g-recaptcha")) {
		response_name = "g-recaptcha-response";

		if ("undefined" === typeof widget_id) {
			widget_id = is_login
				? google_recaptcha_login
				: google_recaptcha_user_registration;
		}

		var grecaptchaObj = getSafeCaptchaObject("grecaptcha");

		if (grecaptchaObj && grecaptchaObj.reset) {
			grecaptchaObj.reset(widget_id);
			captcha_reset = "string" !== typeof widget_id;
		}

		// Invisible reCAPTCHA mints its token through execute(), not on render.
		if ("invisible" === $node.attr("data-size")) {
			if (grecaptchaObj && grecaptchaObj.execute) {
				grecaptchaObj.execute();
			}

			return;
		}
	}

	// Only blank a spent token once the widget is known to be able to replace
	// it. Blanking after a reset that silently failed would strand the visitor
	// on a captcha error next to a widget that still reads as solved.
	if (captcha_reset && response_name) {
		$form.find('[name="' + response_name + '"]').val("");
	} else {
		ur_release_form_for_captcha($form);
	}
};

// Create a simple fallback captcha that always works
var createFallbackCaptcha = function(type) {
	return {
		render: function(element, options) {
			// Convert string ID to DOM element if needed
			if (typeof element === 'string') {
				element = document.getElementById(element);
			}

			if (!element || !element.nodeType) {
				return 'captcha-error';
			}

			// Clear element
			element.innerHTML = '';

			// Create a placeholder div
			var placeholder = document.createElement('div');
			placeholder.innerHTML = '<div style="display: flex; justify-content: center; align-items: center; padding: 10px; width: 100%;"><span class="ur-front-spinner"></div></div>';
			element.appendChild(placeholder);

			// Try to load the real captcha
			if (type === 'hcaptcha') {
				loadHcaptchaWithCallback(element, options);
			} else {
				loadRecaptchaWithCallback(element, options);
			}

			return 'fallback-captcha-widget';
		},
		reset: function(widgetId) {
			// Fallback reset - do nothing
		},
		execute: function(siteKey, options) {
			// Fallback execute - do nothing
		},
		getResponse: function(widgetId) {
			// Fallback getResponse - return empty string
			return '';
		}
	};
};

// Load hCaptcha with callback
var loadHcaptchaWithCallback = function(element, options) {
	// Add delay to hCaptcha to avoid race condition with reCAPTCHA
	setTimeout(function() {
		var uniqueId = 'fallback_hcaptcha_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
		var callbackName = 'onloadFallbackHcaptcha_' + uniqueId;

		window[callbackName] = function() {
			if (window.hcaptcha && window.hcaptcha.render && window.hcaptcha !== window.grecaptcha) {
				element.innerHTML = '';
				window.hcaptcha.render(element, options);
				delete window[callbackName];
			}
		};

		var script = document.createElement('script');
		script.src = 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=' + callbackName;
		script.onload = function() {
			setTimeout(function() {
				if (window.hcaptcha && window.hcaptcha.render && window.hcaptcha !== window.grecaptcha && element) {
					element.innerHTML = '';
					window.hcaptcha.render(element, options);
				}
			}, 2000);
		};
		document.head.appendChild(script);
	}, 1500); // 1.5 second delay for hCaptcha
};

// Load reCAPTCHA with callback
var loadRecaptchaWithCallback = function(element, options) {
	var uniqueId = 'fallback_recaptcha_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
	var callbackName = 'onloadFallbackRecaptcha_' + uniqueId;

	window[callbackName] = function() {
		if (window.grecaptcha && window.grecaptcha.render && window.grecaptcha !== window.hcaptcha) {
			element.innerHTML = '';
			window.grecaptcha.render(element, options);
			delete window[callbackName];
		}
	};

	var script = document.createElement('script');
	script.src = 'https://www.google.com/recaptcha/api.js?render=explicit&callback=' + callbackName;
	script.onload = function() {
		setTimeout(function() {
			if (window.grecaptcha && window.grecaptcha.render && window.grecaptcha !== window.hcaptcha && element) {
				element.innerHTML = '';
				window.grecaptcha.render(element, options);
			}
		}, 2000);
	};
	document.head.appendChild(script);
};

var backupOriginalGlobals = function() {
	if (typeof window.grecaptcha !== 'undefined') {
		originalGrecaptcha = window.grecaptcha;
		isolatedGrecaptcha = Object.assign({}, window.grecaptcha);
		isolatedGrecaptcha2 = JSON.parse(JSON.stringify(window.grecaptcha));
	}
	if (typeof window.hcaptcha !== 'undefined') {
		originalHcaptcha = window.hcaptcha;
	}
	if (typeof window.turnstile !== 'undefined') {
		originalTurnstile = window.turnstile;
	}
};

// Function to load fresh reCAPTCHA when all backups are hCaptcha
var freshRecaptchaLoading = false;
var loadFreshRecaptcha = function() {
	// Only pull in Google reCAPTCHA when it is actually the configured captcha.
	// Loading it on an hCaptcha-only form adds a second SDK whose compat shim
	// then conflicts on window.grecaptcha. (Both v2 and v3 use ur_recaptcha_code.)
	if (typeof ur_recaptcha_code === 'undefined' || !ur_recaptcha_code.site_key) {
		return;
	}
	if (typeof window.ur_fresh_grecaptcha === 'undefined' && !freshRecaptchaLoading) {
		freshRecaptchaLoading = true;
		var script = document.createElement('script');
		script.src = 'https://www.google.com/recaptcha/api.js?render=explicit&callback=onloadURFreshRecaptcha';
		script.onload = function() {
			setTimeout(function() {
				if (window.grecaptcha && window.grecaptcha !== window.hcaptcha) {
					window.ur_fresh_grecaptcha = window.grecaptcha;
				}
			}, 1000);
		};
		script.onerror = function() {
			freshRecaptchaLoading = false;
		};
		document.head.appendChild(script);
	}
};

// Callback for fresh reCAPTCHA
window.onloadURFreshRecaptcha = function() {
	if (window.grecaptcha && window.grecaptcha.render) {
		window.ur_fresh_grecaptcha = window.grecaptcha;
	}
};



// Initialize conflict detection
backupOriginalGlobals();

// Monitor for script loading to capture globals and detect hCaptcha/reCAPTCHA conflicts
var monitorScriptLoading = function() {
	// Check if reCAPTCHA script is loaded
	if (typeof window.grecaptcha !== 'undefined' && !originalGrecaptcha) {
		originalGrecaptcha = window.grecaptcha;
		isolatedGrecaptcha = Object.assign({}, window.grecaptcha);
		isolatedGrecaptcha2 = JSON.parse(JSON.stringify(window.grecaptcha));
	}

	// Check if hCaptcha script is loaded
	if (typeof window.hcaptcha !== 'undefined' && !originalHcaptcha) {
		originalHcaptcha = window.hcaptcha;
	}

	// Check for conflict and try to capture the real reCAPTCHA
	if (typeof window.grecaptcha !== 'undefined' && typeof window.hcaptcha !== 'undefined' &&
		window.grecaptcha !== window.hcaptcha && !originalGrecaptcha) {
		originalGrecaptcha = window.grecaptcha;
		isolatedGrecaptcha = Object.assign({}, window.grecaptcha);
		isolatedGrecaptcha2 = JSON.parse(JSON.stringify(window.grecaptcha));
	}

	// If they're the same object, we have a conflict - try to load fresh reCAPTCHA
	if (typeof window.grecaptcha !== 'undefined' && typeof window.hcaptcha !== 'undefined' &&
		window.grecaptcha === window.hcaptcha) {
		// This means hCaptcha has overwritten grecaptcha
		// Try to load fresh reCAPTCHA if we don't have a valid backup
		if (!originalGrecaptcha || originalGrecaptcha === window.hcaptcha) {
			loadFreshRecaptcha();
		}
	}

	// Additional check: if we have both objects but they're different, make sure we have backups
	if (typeof window.grecaptcha !== 'undefined' && typeof window.hcaptcha !== 'undefined' &&
		window.grecaptcha !== window.hcaptcha) {
		// Make sure we have reCAPTCHA backup
		if (!originalGrecaptcha || originalGrecaptcha === window.hcaptcha) {
			originalGrecaptcha = window.grecaptcha;
			isolatedGrecaptcha = Object.assign({}, window.grecaptcha);
			isolatedGrecaptcha2 = JSON.parse(JSON.stringify(window.grecaptcha));
		}
		// Make sure we have hCaptcha backup
		if (!originalHcaptcha || originalHcaptcha === window.grecaptcha) {
			originalHcaptcha = window.hcaptcha;
		}
	}
};

// Monitor script loading until both captcha globals are captured (max ~5s)
var monitorScriptLoadingInterval = setInterval(function() {
	monitorScriptLoading();
	// Stop once hCaptcha is confirmed loaded (its presence triggered any needed conflict resolution)
	if (typeof window.hcaptcha !== 'undefined' && originalHcaptcha) {
		clearInterval(monitorScriptLoadingInterval);
	}
}, 100);
setTimeout(function() { clearInterval(monitorScriptLoadingInterval); }, 5000);

var immediateCapture = function() {
	if (typeof window.grecaptcha !== 'undefined' && !originalGrecaptcha) {
		originalGrecaptcha = window.grecaptcha;
		isolatedGrecaptcha = Object.assign({}, window.grecaptcha);
		isolatedGrecaptcha2 = JSON.parse(JSON.stringify(window.grecaptcha));
	}
};

// Run immediate capture
immediateCapture();

var protectGrecaptcha = function() {
	if (originalGrecaptcha && typeof window.grecaptcha !== 'undefined') {
		try {
			var grecaptchaProxy = new Proxy(originalGrecaptcha, {
				set: function(target, property, value) {
					target[property] = value;
					return true;
				}
			});

			// Keep this writable/configurable: locking it down makes hCaptcha's
			// grecaptcha compat shim throw "Cannot assign to read only property".
			Object.defineProperty(window, 'grecaptcha', {
				value: grecaptchaProxy,
				writable: true,
				configurable: true
			});
		} catch (e) {
			// Silent fail
		}
	}
};

// Try to protect grecaptcha after a short delay to ensure it's loaded
setTimeout(protectGrecaptcha, 2000);

(function ($) {
	var ursL10n = user_registration_params.ursL10n;

	var user_registration_recaptcha_init = function () {
		$(function () {
			request_recaptcha_token();
		});
	};

	user_registration_recaptcha_init();

	// /**
	//  * Reinitialize the form again after page is fully loaded,
	//  * in order to support third party popup plugins like elementor.
	//  *
	//  * @since 1.9.0
	//  */
	// $(window).on("load", function () {
	// 	user_registration_recaptcha_init();
	// });

	$(function () {
		$(document).on(
			"user_registration_frontend_before_form_submit",
			function (event, data, $registration_form, $error_message) {
				var active_recaptcha_code =
					("undefined" !== typeof ur_recaptcha_code && ur_recaptcha_code.site_key) ? ur_recaptcha_code :
					("undefined" !== typeof ur_cloudflare_recaptcha_code && ur_cloudflare_recaptcha_code.site_key) ? ur_cloudflare_recaptcha_code :
					("undefined" !== typeof ur_hcaptcha_recaptcha_code && ur_hcaptcha_recaptcha_code.site_key) ? ur_hcaptcha_recaptcha_code :
					("undefined" !== typeof ur_v3_recaptcha_code && ur_v3_recaptcha_code.site_key) ? ur_v3_recaptcha_code :
					null;

				if (active_recaptcha_code !== null) {
					if (
						"1" == $registration_form.data("captcha-enabled") &&
						active_recaptcha_code.site_key.length
					) {
						// Spent widgets are refreshed once the response comes back,
						// so only the types that mint a token on demand act here.
						if (active_recaptcha_code.version == "v3") {
							var captchaResponse = $registration_form
								.find('[name="g-recaptcha-response"]')
								.val() || "";
							request_recaptcha_token();
						} else if (active_recaptcha_code.version == "hCaptcha") {
							var captchaResponse = $registration_form
								.find('[name="h-captcha-response"]')
								.val() || "";
						} else if (active_recaptcha_code.version == "cloudflare") {
							var captchaResponse = $registration_form
								.find('[name="cf-turnstile-response"]')
								.val() || "";
						} else {
							var captchaResponse = $registration_form
								.find('[name="g-recaptcha-response"]')
								.val() || "";

							if (active_recaptcha_code.is_invisible) {
								var grecaptchaObj = getSafeCaptchaObject('grecaptcha');
								if (grecaptchaObj && grecaptchaObj.execute) {
									grecaptchaObj.execute();
								}
							}
						}

						if (0 === captchaResponse.length) {
							// An empty field while the widget is still minting is
							// not something the visitor got wrong, so it must not
							// read as a captcha failure.
							$error_message["message"] =
								$registration_form.data("ur-captcha-pending") &&
								ursL10n.captcha_pending
									? ursL10n.captcha_pending
									: ursL10n.captcha_error;
						}
					}
				}
			}
		);

		$(document).on(
			"user_registration_after_login_failed",
			function (event, $login_form) {
				if (
					"undefined" !== typeof ur_recaptcha_code &&
					ur_recaptcha_code.site_key.length
				) {
					var ur_recaptcha_node = $login_form
						.closest("form")
						.find(
							"#ur-recaptcha-node #node_recaptcha_login.g-recaptcha"
						).length;
					var ur_recaptcha_node_hcaptcha = $login_form
						.closest("form")
						.find(
							"#ur-recaptcha-node #node_recaptcha_login.g-recaptcha-hcaptcha"
						).length;
					var ur_recaptcha_node_cloudflare = $login_form
						.closest("form")
						.find(
							"#ur-recaptcha-node #node_recaptcha_login.cf-turnstile"
						).length;
					var ur_recaptcha_node_v3 = $login_form
						.closest("form")
						.find(
							"#ur-recaptcha-node #node_recaptcha_login.g-recaptcha-v3"
						).length;
					if (
						ur_recaptcha_node !== 0 ||
						ur_recaptcha_node_hcaptcha !== 0 ||
						ur_recaptcha_node_cloudflare !== 0 ||
						ur_recaptcha_node_v3 !== 0
					) {
						if (ur_recaptcha_code.version == "v3") {
							request_recaptcha_token();
						} else if (ur_recaptcha_code.version == "hCaptcha") {
							var hcaptchaObj = getSafeCaptchaObject('hcaptcha');
							if (hcaptchaObj && hcaptchaObj.reset) {
								hcaptchaObj.reset(hcaptcha_login);
							}
						} else if (ur_recaptcha_code.version == "cloudflare") {
							var turnstileObj = getSafeCaptchaObject('turnstile');
							if (turnstileObj && turnstileObj.reset) {
								turnstileObj.reset(turnstile_login);
							}
						} else {
							var grecaptchaObj = getSafeCaptchaObject('grecaptcha');
							if (grecaptchaObj && grecaptchaObj.reset) {
							for (var i = 0; i <= google_recaptcha_login; i++) {
									grecaptchaObj.reset(i);
								}
								if (ur_recaptcha_code.is_invisible && grecaptchaObj.execute) {
									grecaptchaObj.execute();
							}
							}
						}
					}
				}
			}
		);

		/**
		 * Replace the token a rejected submission already spent.
		 *
		 * This fires for every response, ahead of the modules that suppress the
		 * later completion events, so it also covers a membership form whose
		 * payment step fails after registration itself succeeded.
		 *
		 * @since 5.2.9
		 */
		$(document).on(
			"user_registration_frontend_before_ajax_complete_success_message",
			function (event, ajax_response, ajaxFlag, $registration_form) {
				if ("1" != $registration_form.data("captcha-enabled")) {
					return;
				}

				var succeeded = false;

				try {
					succeeded =
						true ===
						JSON.parse(ajax_response.responseText).success;
				} catch (e) {}

				if (!succeeded) {
					ur_refresh_form_captcha($registration_form);
				}
			}
		);
	});
})(jQuery);

var google_recaptcha_user_registration,
	google_recaptcha_login,
	hcaptcha_user_registration,
	hcaptcha_login,
	turnstile_user_registration,
	turnstile_login;

// hCaptcha callback function
var onloadURHcaptchaCallback = function () {
	setTimeout(function () {
		onloadURHcaptchaCallbackHandler();
	}, 200);
};

// reCAPTCHA callback function
var onloadURRecaptchaCallback = function () {
	setTimeout(function () {
		onloadURRecaptchaCallbackHandler();
	}, 200);
};

// Cloudflare Turnstile callback function
var onloadURTurnstileCallback = function () {
	setTimeout(function () {
		onloadURTurnstileCallbackHandler();
	}, 200);
};

// Original callback function for backward compatibility
var onloadURCallback = function () {
	// Check which captcha type is being used and call appropriate handler
	if (typeof ur_hcaptcha_recaptcha_code !== 'undefined' && ur_hcaptcha_recaptcha_code.site_key) {
		onloadURHcaptchaCallbackHandler();
	} else if (typeof ur_recaptcha_code !== 'undefined' && ur_recaptcha_code.site_key) {
		onloadURRecaptchaCallbackHandler();
	} else if (typeof ur_cloudflare_recaptcha_code !== 'undefined' && ur_cloudflare_recaptcha_code.site_key) {
		onloadURTurnstileCallbackHandler();
	}
};

// hCaptcha specific callback handler
var onloadURHcaptchaCallbackHandler = function () {
	setTimeout(function () {
		// Only handle hCaptcha forms
		jQuery(".ur-frontend-form")
			.find("form.register")
			.each(function (i) {
				$this = jQuery(this);
				var form_id = $this.closest(".ur-frontend-form").attr("id");

				var node_recaptcha_register = $this.find(
					"#ur-recaptcha-node #node_recaptcha_register"
				).length;

				// Only process if it's an hCaptcha form
				if ($this.find(
					"#ur-recaptcha-node #node_recaptcha_register"
				).hasClass('g-recaptcha-hcaptcha')) {
					var captcha_type = 'hCaptcha',
						recaptcha_code = ur_hcaptcha_recaptcha_code;

				if (
					"undefined" !== typeof captcha_type &&
					"undefined" !== typeof recaptcha_code &&
					recaptcha_code.site_key &&
					recaptcha_code.site_key.length
				) {
					if (node_recaptcha_register !== 0) {
							var hcaptchaElement = $this.find("#ur-recaptcha-node .g-recaptcha-hcaptcha");
							if (hcaptchaElement.length > 0) {
								hcaptchaElement.attr("id", "node_recaptcha_register_" + form_id);
								var hcaptchaObj = getSafeCaptchaObject('hcaptcha');
								if (hcaptchaObj && hcaptchaObj.render) {
									try {
										hcaptcha_user_registration = hcaptchaObj.render(
											"node_recaptcha_register_" + form_id,
											{
												sitekey: recaptcha_code.site_key,
												theme: "light",
												style: "transform:scale(0.77);-webkit-transform:scale(0.77);transform-origin:0 0;-webkit-transform-origin:0 0;",
											}
										);
										hcaptchaElement.data("ur-widget-id", hcaptcha_user_registration);
									} catch (error) {
										// Silent fail
									}
								}
							}
						}
					}
				}
			});

		// Handle hCaptcha login forms
		jQuery(".ur-frontend-form")
			.find("form.login")
			.each(function (i) {
				$this = jQuery(this);
				var ur_recaptcha_node = $this.find("#ur-recaptcha-node");
				if (
					"undefined" !== typeof ur_hcaptcha_recaptcha_code &&
					ur_hcaptcha_recaptcha_code.site_key.length
				) {
					if (ur_recaptcha_node.length !== 0) {
						if ("hCaptcha" === ur_hcaptcha_recaptcha_code.version) {
							var hcaptchaObj = getSafeCaptchaObject('hcaptcha');
							if (hcaptchaObj && hcaptchaObj.render) {
								hcaptcha_login = hcaptchaObj.render(
									ur_recaptcha_node
										.find(".g-recaptcha-hcaptcha")
										.attr("id"),
									{
										sitekey: ur_hcaptcha_recaptcha_code.site_key,
										theme: "light",
										style: "transform:scale(0.77);-webkit-transform:scale(0.77);transform-origin:0 0;-webkit-transform-origin:0 0;",
									}
								);
								ur_recaptcha_node
									.find(".g-recaptcha-hcaptcha")
									.data("ur-widget-id", hcaptcha_login);
							}
						}
					}
				}
			});

		// Handle hCaptcha lost password forms
		jQuery(".ur-frontend-form")
			.find("form.ur_lost_reset_password")
			.each(function (i) {
				$this = jQuery(this);
				var ur_recaptcha_node = $this.find("#ur-recaptcha-node");
				if ("undefined" !== typeof ur_hcaptcha_recaptcha_code) {
					if (ur_recaptcha_node.length !== 0) {
						if ("hCaptcha" === ur_hcaptcha_recaptcha_code.version) {
							var hcaptchaObj = getSafeCaptchaObject('hcaptcha');
							if (hcaptchaObj && hcaptchaObj.render) {
								google_recaptcha_ur_lost_reset_password =
									hcaptchaObj.render(
										ur_recaptcha_node
											.find(".g-recaptcha-hcaptcha")
											.attr("id"),
										{
											sitekey: ur_hcaptcha_recaptcha_code.site_key,
											theme: "light",
									style: "transform:scale(0.77);-webkit-transform:scale(0.77);transform-origin:0 0;-webkit-transform-origin:0 0;",
								}
							);
							}
						}
					}
				}
			});
	}, 1000);
};

// reCAPTCHA specific callback handler
var onloadURRecaptchaCallbackHandler = function () {
	setTimeout(function () {
		// Only handle reCAPTCHA forms
		jQuery(".ur-frontend-form")
			.find("form.register")
			.each(function (i) {
				$this = jQuery(this);
				var form_id = $this.closest(".ur-frontend-form").attr("id");

				var node_recaptcha_register = $this.find(
					"#ur-recaptcha-node #node_recaptcha_register"
				).length;

				// Only process if it's a reCAPTCHA form (v2 or v3)
				if ($this.find(
					"#ur-recaptcha-node #node_recaptcha_register"
				).hasClass('g-recaptcha') || $this.find(
					"#ur-recaptcha-node #node_recaptcha_register"
				).hasClass('g-recaptcha-v3')) {
					var captcha_type, recaptcha_code;

					if ($this.find(
						"#ur-recaptcha-node #node_recaptcha_register"
					).hasClass('g-recaptcha')) {
						captcha_type = 'v2';
						recaptcha_code = ur_recaptcha_code;
					} else if ($this.find(
						"#ur-recaptcha-node #node_recaptcha_register"
					).hasClass('g-recaptcha-v3')) {
						captcha_type = 'v3';
						recaptcha_code = ur_v3_recaptcha_code;
					}


					if (
						"undefined" !== typeof captcha_type &&
						"undefined" !== typeof recaptcha_code &&
						recaptcha_code.site_key &&
						recaptcha_code.site_key.length
					) {
						if (node_recaptcha_register !== 0) {
							$this
								.find("#ur-recaptcha-node .g-recaptcha")
								.attr("id", "node_recaptcha_register_" + form_id);

							var grecaptchaObj = getSafeCaptchaObject('grecaptcha');
							if (grecaptchaObj && grecaptchaObj.render) {
								google_recaptcha_user_registration = grecaptchaObj.render(
								"node_recaptcha_register_" + form_id,
								{
									sitekey: recaptcha_code.site_key,
									theme: "light",
									style: "transform:scale(0.77);-webkit-transform:scale(0.77);transform-origin:0 0;-webkit-transform-origin:0 0;",
								}
							);
								$this
									.find("#node_recaptcha_register_" + form_id)
									.data("ur-widget-id", google_recaptcha_user_registration);

								if (recaptcha_code.is_invisible && grecaptchaObj.execute) {
									grecaptchaObj.execute(google_recaptcha_user_registration);
								}
						}
						}
					}
				}
			});

		// Handle reCAPTCHA login forms
		jQuery(".ur-frontend-form")
			.find("form.login")
			.each(function (i) {
				$this = jQuery(this);
				var ur_recaptcha_node = $this.find("#ur-recaptcha-node");
				if (
					"undefined" !== typeof ur_recaptcha_code &&
					ur_recaptcha_code.site_key.length
				) {
					if (ur_recaptcha_node.length !== 0) {
						// Only handle reCAPTCHA (v2/v3) forms
						if (ur_recaptcha_code.version !== "hCaptcha" && ur_recaptcha_code.version !== "cloudflare") {
							var grecaptchaObj = getSafeCaptchaObject('grecaptcha');
							if (grecaptchaObj && grecaptchaObj.render) {
								google_recaptcha_login = grecaptchaObj.render(
								ur_recaptcha_node.find(".g-recaptcha").attr("id"),
								{
									sitekey: ur_recaptcha_code.site_key,
									theme: "light",
									style: "transform:scale(0.77);-webkit-transform:scale(0.77);transform-origin:0 0;-webkit-transform-origin:0 0;",
								}
							);
								ur_recaptcha_node
									.find(".g-recaptcha")
									.data("ur-widget-id", google_recaptcha_login);
								if (ur_recaptcha_code.is_invisible && grecaptchaObj.execute) {
									grecaptchaObj.execute(google_recaptcha_login);
								}
						}
						}
					}
				}
			});

		// Handle reCAPTCHA lost password forms
		jQuery(".ur-frontend-form")
			.find("form.ur_lost_reset_password")
			.each(function (i) {
				$this = jQuery(this);
				var ur_recaptcha_node = $this.find("#ur-recaptcha-node");
				if ("undefined" !== typeof ur_recaptcha_code) {
					if (ur_recaptcha_node.length !== 0) {
						// Only handle reCAPTCHA (v2/v3) forms
						if (ur_recaptcha_code.version !== "hCaptcha" && ur_recaptcha_code.version !== "cloudflare") {
							var grecaptchaObj = getSafeCaptchaObject('grecaptcha');
							if (grecaptchaObj && grecaptchaObj.render) {
							google_recaptcha_ur_lost_reset_password =
									grecaptchaObj.render(
									ur_recaptcha_node
											.find(".g-recaptcha")
										.attr("id"),
									{
										sitekey: ur_recaptcha_code.site_key,
										theme: "light",
										style: "transform:scale(0.77);-webkit-transform:scale(0.77);transform-origin:0 0;-webkit-transform-origin:0 0;",
									}
								);
								if (ur_recaptcha_code.is_invisible && grecaptchaObj.execute) {
									grecaptchaObj.execute(
										google_recaptcha_ur_lost_reset_password
									);
								}
							}
						}
					}
				}
			});
	}, 1000);
};

// Cloudflare Turnstile specific callback handler
var onloadURTurnstileCallbackHandler = function () {
	setTimeout(function () {
		// Only handle Cloudflare Turnstile forms
		jQuery(".ur-frontend-form")
			.find("form.register")
			.each(function (i) {
				$this = jQuery(this);
				var form_id = $this.closest(".ur-frontend-form").attr("id");

				var node_recaptcha_register = $this.find(
					"#ur-recaptcha-node #node_recaptcha_register"
				).length;

				// Only process if it's a Cloudflare Turnstile form
				if ($this.find(
					"#ur-recaptcha-node #node_recaptcha_register"
				).hasClass('cf-turnstile')) {
					var captcha_type = 'cloudflare',
						recaptcha_code = ur_cloudflare_recaptcha_code;

					if (
						"undefined" !== typeof captcha_type &&
						"undefined" !== typeof recaptcha_code &&
						recaptcha_code.site_key &&
						recaptcha_code.site_key.length
					) {
						if (node_recaptcha_register !== 0) {
							$this
								.find("#ur-recaptcha-node .cf-turnstile")
								.attr("id", "node_recaptcha_register_" + form_id);
							var turnstileObj = getSafeCaptchaObject('turnstile');
							if (turnstileObj && turnstileObj.render) {
								// Kept on the node so a spent widget can be rebuilt after a failed submission.
								var turnstile_params = ur_get_turnstile_params(
									recaptcha_code,
									jQuery(this)
								);
								turnstile_user_registration = turnstileObj.render(
									"#node_recaptcha_register_" + form_id,
									turnstile_params
								);
								$this
									.find("#node_recaptcha_register_" + form_id)
									.data("ur-widget-id", turnstile_user_registration)
									.data("ur-render-params", turnstile_params);
							}
						}
					}
				}
			});

		// Handle Cloudflare Turnstile login forms
		jQuery(".ur-frontend-form")
			.find("form.login")
			.each(function (i) {
				$this = jQuery(this);
				var ur_recaptcha_node = $this.find("#ur-recaptcha-node");
				if (
					"undefined" !== typeof ur_cloudflare_recaptcha_code &&
					ur_cloudflare_recaptcha_code.site_key.length
				) {
					if (ur_recaptcha_node.length !== 0) {
						if ("cloudflare" === ur_cloudflare_recaptcha_code.version) {
							var turnstileObj = getSafeCaptchaObject('turnstile');
							if (turnstileObj && turnstileObj.render) {
								var turnstile_login_params = ur_get_turnstile_params(
									ur_cloudflare_recaptcha_code,
									jQuery(this)
								);
								turnstile_login = turnstileObj.render(
									"#" + ur_recaptcha_node
										.find(".cf-turnstile")
										.attr("id"),
									turnstile_login_params
								);
								ur_recaptcha_node
									.find(".cf-turnstile")
									.data("ur-widget-id", turnstile_login)
									.data("ur-render-params", turnstile_login_params);
							}
						}
					}
				}
			});

		// Handle Cloudflare Turnstile lost password forms
		jQuery(".ur-frontend-form")
			.find("form.ur_lost_reset_password")
			.each(function (i) {
				$this = jQuery(this);
				var ur_recaptcha_node = $this.find("#ur-recaptcha-node");
				if ("undefined" !== typeof ur_cloudflare_recaptcha_code) {
					if (ur_recaptcha_node.length !== 0) {
						if ("cloudflare" === ur_cloudflare_recaptcha_code.version) {
							var turnstileObj = getSafeCaptchaObject('turnstile');
							if (turnstileObj && turnstileObj.render) {
							google_recaptcha_ur_lost_reset_password =
									turnstileObj.render(
										"#" + ur_recaptcha_node
											.find(".cf-turnstile")
										.attr("id"),
									{
											sitekey: ur_cloudflare_recaptcha_code.site_key,
											theme: ur_cloudflare_recaptcha_code.theme_mode,
										style: "transform:scale(0.77);-webkit-transform:scale(0.77);transform-origin:0 0;-webkit-transform-origin:0 0;",
									}
								);
						}
						}
					}
				}
			});
	}, 1000);
};


function request_recaptcha_token() {
	// Determine which reCAPTCHA code variable to use based on what's available
	var recaptchaCode = null;
	if (typeof ur_v3_recaptcha_code !== 'undefined') {
		recaptchaCode = ur_v3_recaptcha_code;
	} else if (typeof ur_recaptcha_code !== 'undefined') {
		recaptchaCode = ur_recaptcha_code;
	}

	if (!recaptchaCode || !recaptchaCode.site_key || !recaptchaCode.site_key.length) {
		return;
	}

	var node_recaptcha_register = jQuery(".ur-frontend-form").find(
		"form.register #ur-recaptcha-node #node_recaptcha_register.g-recaptcha-v3"
	).length;
	if (node_recaptcha_register !== 0) {
			grecaptcha.ready(function () {
				grecaptcha
					.execute(recaptchaCode.site_key, {
						action: "register",
					})
					.then(function (token) {
						jQuery("form.register")
							.find("#g-recaptcha-response")
							.text(token);
					});
			});
	}

	var node_recaptcha_login = jQuery(".ur-frontend-form").find(
		"form.login #ur-recaptcha-node #node_recaptcha_login.g-recaptcha-v3"
	).length;
	if (node_recaptcha_login !== 0) {
		grecaptcha.ready(function () {
			grecaptcha
				.execute(recaptchaCode.site_key, {
					action: "login",
				})
				.then(function (token) {
					jQuery("form.login")
						.find("#g-recaptcha-response")
						.text(token);
				});
		});
	}

	var node_recaptcha_ur_lost_reset_password = jQuery(
		".ur-frontend-form"
	).find(
		"form.ur_lost_reset_password #ur-recaptcha-node #node_recaptcha_lost_password.g-recaptcha-v3"
	).length;
	if (node_recaptcha_ur_lost_reset_password !== 0) {
		if (typeof grecaptcha !== 'undefined' && grecaptcha.ready && grecaptcha.execute) {
			grecaptcha.ready(function () {
				grecaptcha
					.execute(recaptchaCode.site_key, {
						action: "lost_password",
					})
					.then(function (token) {
						jQuery("form.ur_lost_reset_password")
							.find("#g-recaptcha-response")
							.text(token);
					});
			});
		}
	}
}
