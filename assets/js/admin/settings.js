/* global user_registration_settings_params, user_registration_settings_params, UR_Snackbar */
// Hide default buttons immediately on page load (before jQuery is ready)
(function () {
	function hideDefaultButtonsImmediately() {
		if (document.querySelectorAll) {
			var buttons = document.querySelectorAll(
				".wp-picker-default, .button.button-small.wp-picker-default"
			);
			buttons.forEach(function (btn) {
				if (btn && btn.style) {
					btn.style.display = "none";
					btn.style.visibility = "hidden";
					btn.style.opacity = "0";
					btn.style.width = "0";
					btn.style.height = "0";
					btn.style.padding = "0";
					btn.style.margin = "0";
					btn.style.border = "0";
					btn.style.position = "absolute";
					btn.style.left = "-9999px";
					btn.style.pointerEvents = "none";
				}
			});
		}
	}

	if (document.readyState === "loading") {
		document.addEventListener(
			"DOMContentLoaded",
			hideDefaultButtonsImmediately
		);
	} else {
		hideDefaultButtonsImmediately();
	}

	hideDefaultButtonsImmediately();

	if (
		document.head &&
		!document.getElementById("ur-hide-default-button-style")
	) {
		var style = document.createElement("style");
		style.id = "ur-hide-default-button-style";
		style.textContent =
			".wp-picker-default, .button.button-small.wp-picker-default{display:none!important;visibility:hidden!important;opacity:0!important;width:0!important;height:0!important;padding:0!important;margin:0!important;border:0!important;position:absolute!important;left:-9999px!important;pointer-events:none!important}";
		document.head.appendChild(style);
	}
})();

(function ($) {
	if (UR_Snackbar) {
		var snackbar = new UR_Snackbar();
	}

	$("select#user_registration_allowed_screens")
		.on("change", function () {
			if ("specific" === $(this).val()) {
				$(this)
					.closest(".user-registration-global-settings")
					.next(".user-registration-global-settings")
					.hide();
				$(this)
					.closest(".user-registration-global-settings")
					.next()
					.next(".user-registration-global-settings")
					.show();
			} else if ("all_except" === $(this).val()) {
				$(this)
					.closest(".user-registration-global-settings")
					.next(".user-registration-global-settings")
					.show();
				$(this)
					.closest(".user-registration-global-settings")
					.next()
					.next(".user-registration-global-settings")
					.hide();
			} else {
				$(this)
					.closest(".user-registration-global-settings")
					.next(".user-registration-global-settings")
					.hide();
				$(this)
					.closest(".user-registration-global-settings")
					.next()
					.next(".user-registration-global-settings")
					.hide();
			}
		})
		.trigger("change");

	$(".colorpick").each(function () {
		var $input = $(this);
		var alphaEnabled =
			$input.data("alpha") === true ||
			$input.attr("data-alpha") === "true";

		var domElement = $input[0];
		var currentValue =
			(domElement && domElement.value) ||
			$input.attr("value") ||
			$input.val() ||
			$input.data("current-value") ||
			$input.attr("data-current-value") ||
			"";
		var defaultValue =
			$input.data("default-value") ||
			$input.attr("data-default-value") ||
			"";
		var initialValue = currentValue || defaultValue;

		if (initialValue) {
			initialValue = initialValue.toString().trim();
			if (!initialValue) {
				initialValue = "";
			}
		}

		if (initialValue) {
			$input.val(initialValue);
			$input.attr("value", initialValue);
			if (domElement) {
				domElement.value = initialValue;
			}
		}

		var colorPickerOptions = {
			change: function (event, ui) {
				var $input = $(this);
				var colorValue = ui.color.toString();

				$input
					.parent()
					.find(".colorpickpreview")
					.css({ backgroundColor: colorValue });

				var $container = $input.closest(".wp-picker-container");
				if ($container.length) {
					var $colorResult = $container.find(".wp-color-result");
					if ($colorResult.length) {
						$colorResult.css("background-color", colorValue);
						var $colorSpan = $colorResult.find("span");
						if ($colorSpan.length) {
							$colorSpan.css("background-color", colorValue);
						}
					}
				}
			},
			clear: function () {
				var $input = $(this);

				$input
					.parent()
					.find(".colorpickpreview")
					.css({ backgroundColor: "" });

				var $container = $input.closest(".wp-picker-container");
				if ($container.length) {
					var $colorResult = $container.find(".wp-color-result");
					if ($colorResult.length) {
						$colorResult.css("background-color", "");
						var $colorSpan = $colorResult.find("span");
						if ($colorSpan.length) {
							$colorSpan.css("background-color", "");
						}
					}
				}
			},
			hide: true,
			border: true,
			width: 255,
			mode: alphaEnabled ? "rgba" : "hex"
		};

		if (initialValue) {
			colorPickerOptions.defaultColor = initialValue;
			colorPickerOptions.color = initialValue;
		}

		$input.wpColorPicker(colorPickerOptions);

		$input.on("change", function () {
			var colorValue = $(this).val();
			var $container = $(this).closest(".wp-picker-container");
			if ($container.length) {
				var $colorResult = $container.find(".wp-color-result");
				if ($colorResult.length) {
					$colorResult.css("background-color", colorValue);
					var $colorSpan = $colorResult.find("span");
					if ($colorSpan.length) {
						$colorSpan.css("background-color", colorValue);
					}
				}
			}
		});

		var hideDefaultButton = function () {
			var $container = $input.closest(".wp-picker-container");
			if ($container.length) {
				var $defaultButton = $container.find(
					".button.button-small.wp-picker-default, .wp-picker-default"
				);
				$defaultButton.css({
					display: "none",
					visibility: "hidden",
					opacity: "0",
					width: "0",
					height: "0",
					padding: "0",
					margin: "0",
					border: "0",
					position: "absolute",
					left: "-9999px",
					"pointer-events": "none"
				});
			}
		};

		hideDefaultButton();
		setTimeout(hideDefaultButton, 0);
		setTimeout(hideDefaultButton, 10);
		setTimeout(hideDefaultButton, 50);
		setTimeout(hideDefaultButton, 100);

		var applyColor = function () {
			if (!initialValue) {
				$input.css("display", "none");
				return;
			}

			var $container = $input.closest(".wp-picker-container");
			if (!$container.length) {
				return;
			}

			var $colorResult = $container.find(".wp-color-result");
			if (!$colorResult.length) {
				return;
			}

			$input.val(initialValue);

			$colorResult.css("background-color", initialValue);

			var $colorSpan = $colorResult.find("span");
			if ($colorSpan.length) {
				$colorSpan.css("background-color", initialValue);
			}

			try {
				if ($input.data("wp-wpColorPicker")) {
					var picker = $input.data("wp-wpColorPicker");
					if (picker.iris && picker.iris._color) {
						picker.iris._color = initialValue;
					}
				}
				if (typeof $input.iris === "function") {
					$input.iris("color", initialValue);
				}
			} catch (e) {}

			$input.css("display", "none");
		};

		setTimeout(applyColor, 50);
		setTimeout(applyColor, 200);
		setTimeout(applyColor, 500);

		setTimeout(function () {
			var $container = $input.closest(".wp-picker-container");
			if ($container.length) {
				var $holder = $container.find(".wp-picker-holder");
				var $inputWrap = $container.find(".wp-picker-input-wrap");
				var inputDefaultValue =
					$input.data("default-value") ||
					$input.attr("data-default-value") ||
					"";

				$input.css("display", "none");

				if ($holder.length && $inputWrap.length) {
					if (!$holder.find($inputWrap).length) {
						$inputWrap.appendTo($holder);
					}
				}

				if ($holder.length) {
					$holder.css({
						position: "absolute",
						top: "100%",
						left: "0",
						zIndex: "100",
						marginTop: "2px"
					});

					var $irisPicker = $container.find(".iris-picker");
					var $irisBorder = $container.find(".iris-border");
					var $colorInput = $container.find(
						".colorpick.wp-color-picker"
					);

					var $defaultButton = $container.find(
						".button.button-small.wp-picker-default, .wp-picker-default"
					);
					$defaultButton.css({
						display: "none",
						visibility: "hidden",
						width: "0",
						height: "0",
						position: "absolute",
						left: "-9999px",
						"pointer-events": "none"
					});

					function togglePickerVisibility() {
						setTimeout(function () {
							var $colorResult =
								$container.find(".wp-color-result");
							var isOpen =
								$container.hasClass("wp-picker-active") ||
								$colorResult.hasClass("wp-picker-open");
							var $defaultButton = $container
								.find(".wp-picker-default")
								.add(
									$container.find(
										".button.button-small.wp-picker-default"
									)
								)
								.add($container.find("input.wp-picker-default"))
								.add(
									$container.find("button.wp-picker-default")
								);

							if (isOpen) {
								$holder.show();
								$irisPicker.show();
								$irisBorder.show();
								$colorInput.show();
								$inputWrap.show();
								if ($defaultButton.length) {
									$defaultButton.each(function () {
										var btn = this;
										var $btn = $(btn);
										if (btn.style) {
											btn.style.setProperty(
												"display",
												"inline-block",
												"important"
											);
											btn.style.setProperty(
												"visibility",
												"visible",
												"important"
											);
											btn.style.setProperty(
												"opacity",
												"1",
												"important"
											);
											btn.style.setProperty(
												"width",
												"auto",
												"important"
											);
											btn.style.setProperty(
												"height",
												"auto",
												"important"
											);
											btn.style.setProperty(
												"position",
												"relative",
												"important"
											);
											btn.style.setProperty(
												"left",
												"auto",
												"important"
											);
											btn.style.setProperty(
												"pointer-events",
												"auto",
												"important"
											);
											btn.style.setProperty(
												"padding",
												"",
												"important"
											);
											btn.style.setProperty(
												"margin",
												"",
												"important"
											);
											btn.style.setProperty(
												"border",
												"",
												"important"
											);
										}
									});
								}
							} else {
								$holder.hide();
								$irisPicker.hide();
								$irisBorder.hide();
								$colorInput.hide();
								$inputWrap.hide();
								$defaultButton.css({
									display: "none",
									visibility: "hidden",
									opacity: "0",
									width: "0",
									height: "0",
									padding: "0",
									margin: "0",
									border: "0",
									position: "absolute",
									left: "-9999px",
									"pointer-events": "none"
								});
							}
						}, 10);
					}

					$container.on(
						"click",
						".wp-color-result",
						togglePickerVisibility
					);

					var observer = new MutationObserver(function (mutations) {
						mutations.forEach(function (mutation) {
							if (
								mutation.type === "attributes" &&
								mutation.attributeName === "class"
							) {
								togglePickerVisibility();
							}
						});
					});

					observer.observe($container[0], {
						attributes: true,
						attributeFilter: ["class"]
					});

					var $colorResult = $container.find(".wp-color-result");
					if ($colorResult.length) {
						var colorResultObserver = new MutationObserver(
							function (mutations) {
								mutations.forEach(function (mutation) {
									if (
										mutation.type === "attributes" &&
										mutation.attributeName === "class"
									) {
										togglePickerVisibility();
									}
								});
							}
						);

						colorResultObserver.observe($colorResult[0], {
							attributes: true,
							attributeFilter: ["class"]
						});
					}

					setTimeout(function () {
						togglePickerVisibility();
					}, 100);
					setTimeout(function () {
						togglePickerVisibility();
					}, 300);
				}

				$container.find(".wp-color-result").css({
					borderRadius: "4px"
				});

				var $colorGroupItem = $container.closest(
					".user-registration-color-group-item"
				);
				if ($colorGroupItem.length) {
					var $label = $colorGroupItem.find(".ur-color-state-label");
					var labelText = $label.text();
					if (labelText) {
						$container
							.find(".wp-color-result")
							.attr("title", labelText);
					}
				}
			}
		}, 50);
	});

	$(document).on(
		"mouseenter",
		".user-registration-color-group-item .wp-color-result",
		function () {
			var $item = $(this).closest(".user-registration-color-group-item");
			var $label = $item.find(".ur-color-state-label");
			var labelText = $label.text();

			if (labelText && !$(this).attr("title")) {
				$(this).attr("title", labelText);
			}
		}
	);

	$(document).ready(function () {
		function hideAllDefaultButtons() {
			$(".wp-picker-default, .button.button-small.wp-picker-default").css(
				{
					display: "none",
					visibility: "hidden",
					opacity: "0",
					width: "0",
					height: "0",
					padding: "0",
					margin: "0",
					border: "0",
					position: "absolute",
					left: "-9999px",
					"pointer-events": "none"
				}
			);
		}

		hideAllDefaultButtons();
		setTimeout(hideAllDefaultButtons, 0);
		setTimeout(hideAllDefaultButtons, 10);
		setTimeout(hideAllDefaultButtons, 50);
		setTimeout(hideAllDefaultButtons, 100);
		setTimeout(hideAllDefaultButtons, 200);

		if (typeof MutationObserver !== "undefined") {
			var buttonObserver = new MutationObserver(function (mutations) {
				mutations.forEach(function (mutation) {
					if (mutation.addedNodes && mutation.addedNodes.length) {
						for (var i = 0; i < mutation.addedNodes.length; i++) {
							var node = mutation.addedNodes[i];
							if (node.nodeType === 1) {
								if (
									node.classList &&
									(node.classList.contains(
										"wp-picker-default"
									) ||
										(node.classList.contains("button") &&
											node.classList.contains(
												"button-small"
											) &&
											node.classList.contains(
												"wp-picker-default"
											)))
								) {
									$(node).css({
										display: "none",
										visibility: "hidden",
										opacity: "0",
										width: "0",
										height: "0",
										padding: "0",
										margin: "0",
										border: "0",
										position: "absolute",
										left: "-9999px",
										"pointer-events": "none"
									});
								}
								if (node.querySelectorAll) {
									var buttons = node.querySelectorAll(
										".wp-picker-default, .button.button-small.wp-picker-default"
									);
									buttons.forEach(function (btn) {
										$(btn).css({
											display: "none",
											visibility: "hidden",
											opacity: "0",
											width: "0",
											height: "0",
											padding: "0",
											margin: "0",
											border: "0",
											position: "absolute",
											left: "-9999px",
											"pointer-events": "none"
										});
									});
								}
							}
						}
					}
				});
			});

			if (document.body) {
				buttonObserver.observe(document.body, {
					childList: true,
					subtree: true
				});
			}
		}
	});

	$(".colorpick, .colorpickpreview")
		.iris({
			change: function (event, ui) {
				var $input = $(this);
				var colorValue = ui.color.toString();

				$input
					.parent()
					.find(".colorpickpreview")
					.css({ backgroundColor: colorValue });

				var $container = $input.closest(".wp-picker-container");
				if ($container.length) {
					var $colorResult = $container.find(".wp-color-result");
					if ($colorResult.length) {
						$colorResult.css("background-color", colorValue);
						var $colorSpan = $colorResult.find("span");
						if ($colorSpan.length) {
							$colorSpan.css("background-color", colorValue);
						}
					}
				}
			},
			hide: true,
			border: true
		})
		.on("click", function () {
			$(".iris-picker").hide();
			$(this)
				.closest(".user-registration-global-settings--field")
				.find("> .iris-picker")
				.show();
		});

	$("body").on("click", function () {
		$(".iris-picker").hide();
	});

	$(".colorpick, .colorpickpreview").on("click", function (event) {
		event.stopPropagation();
	});

	$(function () {
		var changed = false;

		$("input, textarea, select, checkbox").on("change", function () {
			changed = true;
		});

		$(".submit input").on("click", function () {
			window.onbeforeunload = "";
		});
	});

	$(".user-registration").on("click", ".select_all", function () {
		$(this)
			.closest("td")
			.find("select option")
			.attr("selected", "selected");
		$(this).closest("td").find("select").trigger("change");
		return false;
	});

	$(".user-registration").on("click", ".select_none", function () {
		$(this).closest("td").find("select option").prop("selected", false);
		$(this).closest("td").find("select").trigger("change");
		return false;
	});

	var recaptchav2_invisible_input_value = $(".user-registration")
		.find("#user_registration_captcha_setting_invisible_recaptcha_v2")
		.is(":checked");

	if (recaptchav2_invisible_input_value != undefined) {
		handleReCaptchaHideShow(recaptchav2_invisible_input_value);
	}

	$(".user-registration").on(
		"change",
		"input#user_registration_captcha_setting_invisible_recaptcha_v2",
		function () {
			if ($(this).is(":checked")) {
				$("#user_registration_captcha_setting_recaptcha_site_key")
					.closest(".user-registration-global-settings")
					.hide();
				$("#user_registration_captcha_setting_recaptcha_site_secret")
					.closest(".user-registration-global-settings")
					.hide();
				$(
					"#user_registration_captcha_setting_recaptcha_invisible_site_key"
				)
					.closest(".user-registration-global-settings")
					.show();
				$(
					"#user_registration_captcha_setting_recaptcha_invisible_site_secret"
				)
					.closest(".user-registration-global-settings")
					.show();
			} else {
				$("#user_registration_captcha_setting_recaptcha_site_key")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_captcha_setting_recaptcha_site_secret")
					.closest(".user-registration-global-settings")
					.show();
				$(
					"#user_registration_captcha_setting_recaptcha_invisible_site_key"
				)
					.closest(".user-registration-global-settings")
					.hide();
				$(
					"#user_registration_captcha_setting_recaptcha_invisible_site_secret"
				)
					.closest(".user-registration-global-settings")
					.hide();
			}
		}
	);

	function handleReCaptchaHideShow(value) {
		if (value) {
			$("#user_registration_captcha_setting_recaptcha_site_key")
				.closest(".user-registration-global-settings")
				.hide();
			$("#user_registration_captcha_setting_recaptcha_site_secret")
				.closest(".user-registration-global-settings")
				.hide();
			$("#user_registration_captcha_setting_recaptcha_invisible_site_key")
				.closest(".user-registration-global-settings")
				.show();
			$(
				"#user_registration_captcha_setting_recaptcha_invisible_site_secret"
			)
				.closest(".user-registration-global-settings")
				.show();
		} else {
			$("#user_registration_captcha_setting_recaptcha_site_key")
				.closest(".user-registration-global-settings")
				.show();
			$("#user_registration_captcha_setting_recaptcha_site_secret")
				.closest(".user-registration-global-settings")
				.show();
			$("#user_registration_captcha_setting_recaptcha_invisible_site_key")
				.closest(".user-registration-global-settings")
				.hide();
			$(
				"#user_registration_captcha_setting_recaptcha_invisible_site_secret"
			)
				.closest(".user-registration-global-settings")
				.hide();
		}
	}

	$(".ur-redirect-to-login-page").ready(function () {
		var $url = $(".ur-redirect-to-login-page"),
			$check = $("#user_registration_login_options_prevent_core_login"),
			$redirect = $(
				"#user_registration_login_options_login_redirect_url"
			);
		if (!$check.prop("checked")) {
			$url.val("").closest(".single_select_page").css("display", "none");
		} else {
			var $selected_page = $check
				.closest(".ur-login-form-setting-block")
				.find(".ur-redirect-to-login-page")
				.val();
			var login_form_settings = $check.closest(
				".user-registration-login-form-container"
			);
			var wpbody_class =
				$(login_form_settings).closest("#wpbody-content");

			if ("" === $selected_page) {
				$check
					.closest(".ur-login-form-setting-block")
					.find(".ur-redirect-to-login-page")
					.closest(
						".user-registration-login-form-global-settings--field"
					)
					.append(
						'<div class="error inline" style="padding:10px;">' +
							user_registration_settings_params.user_registration_membership_redirect_default_page_message +
							"</div>"
					);
			} else {
				$(wpbody_class)
					.find("#ur-lists-page-topnav")
					.find(".ur_save_login_form_action_button")
					.prop("disabled", false);
				$check
					.closest(".ur-login-form-setting-block")
					.find(".ur-redirect-to-login-page")
					.closest(
						".user-registration-login-form-global-settings--field"
					)
					.find(".error.inline")
					.remove();
			}

			$redirect.prop("required", true);
		}

		$(
			'select[name="user_registration_login_options_login_redirect_url"]'
		).on("select2:unselect", function () {
			$check
				.closest(".ur-login-form-setting-block")
				.find(".ur-redirect-to-login-page")
				.closest(".user-registration-login-form-global-settings--field")
				.append(
					'<div class="error inline" style="padding:10px;">' +
						user_registration_settings_params.user_registration_membership_redirect_default_page_message +
						"</div>"
				);

			$redirect.prop("required", true);
		});
	});

	$("#user_registration_login_options_prevent_core_login").on(
		"change",
		function () {
			var $url = $("#user_registration_login_options_prevent_core_login");

			$("#user_registration_login_options_login_redirect_url")
				.closest(".single_select_page")
				.toggle();
			$("#user_registration_login_options_login_redirect_url").prop(
				"required",
				function () {
					return "checked" === $url.prop("checked") ? true : false;
				}
			);
		}
	);
	$("#user_registration_disable_profile_picture").on("change", function () {
		var is_advanced_fields_active = parseInt(
			user_registration_settings_params.is_advanced_field_active
		);
		if ($(this).prop("checked") && is_advanced_fields_active === 1) {
			$("#user_registration_sync_profile_picture")
				.closest(".user-registration-global-settings")
				.css("display", "flex");
		} else {
			$("#user_registration_sync_profile_picture").prop("checked", false);
			$("#user_registration_sync_profile_picture")
				.closest(".user-registration-global-settings")
				.css("display", "none");
		}
	});
	$("#user_registration_sync_profile_picture").ready(function () {
		$this = $("#user_registration_sync_profile_picture");
		if ($this.prop("checked")) {
			$this
				.closest(".user-registration-global-settings")
				.css("display", "flex");
		} else if (
			$("#user_registration_disable_profile_picture").prop("checked") &&
			parseInt(
				user_registration_settings_params.is_advanced_field_active
			) === 1
		) {
			$this
				.closest(".user-registration-global-settings")
				.css("display", "flex");
		} else {
			$this
				.closest(".user-registration-global-settings")
				.css("display", "none");
		}
	});

	$(".user-registration-custom-file__input").on("change", function () {
		var file = $(".user-registration-custom-file__input").prop("files")[0];

		$(".user-registration-custom-selected-file").html(file.name);
	});

	$(".ur-image-uploader").on("click", function (e) {
		ur_uploader = $(this);
		e.preventDefault();
		var image = wp
			.media({
				library: {
					type: ["image"]
				},
				title: ur_uploader.upload_file,
				multiple: false
			})
			.open()
			.on("select", function (e) {
				var uploaded_image = image.state().get("selection").first();
				var image_url = uploaded_image.toJSON().url;
				ur_uploader.attr("src", image_url);
				if (ur_uploader.hasClass("ur-button")) {
					ur_uploader.siblings("img").show();
					ur_uploader.siblings("img").attr("src", image_url);
					ur_uploader
						.siblings("#user_registration_pdf_logo_image")
						.val(image_url);
					ur_uploader.hide();
					ur_uploader.siblings(".ur-image-remover").show();
				} else {
					ur_uploader.attr("src", image_url);
					ur_uploader
						.siblings("#user_registration_pdf_logo_image")
						.val(image_url);
				}
			});
	});

	$(".ur-image-remover").on("click", function (e) {
		var ur_remover = $(this);
		e.preventDefault();

		ur_remover.siblings("img").attr("src", "");
		ur_remover.siblings("#user_registration_pdf_logo_image").val("");
		ur_remover.siblings(".ur-image-uploader").show();
		ur_remover.hide();
		ur_remover.siblings("img").hide();
	});

	$(".radio-image")
		.find("input")
		.each(function () {
			var $option_selector = $(this);

			$option_selector.on("click", function () {
				$(this).closest("ul").find("label").removeClass("selected");
				$(this).closest("label").addClass("selected");
			});
		});

	$(".user-registration #mainform").on("keyup keypress", function (e) {
		var keyCode = e.keyCode || e.which;
		if (keyCode === 13) {
			e.preventDefault();
			return false;
		}
	});

	$(".user-registration #ur-search-settings").autocomplete({
		source: function (request, response) {
			var search_string = request.term;
			var form_data = new FormData();
			form_data.append("search_string", search_string);
			form_data.append(
				"action",
				"user_registration_search_global_settings"
			);
			form_data.append(
				"security",
				user_registration_settings_params.user_registration_search_global_settings_nonce
			);
			$(".user-registration-search-icon").hide();

			$.ajax({
				url: user_registration_settings_params.ajax_url,
				dataType: "json", // what to expect back from the PHP script, if anything
				cache: false,
				contentType: false,
				processData: false,
				data: form_data,
				type: "post",
				complete: function (responsed) {
					if (responsed.responseJSON.success === true) {
						var results = responsed.responseJSON.data.results;
						response(results);
					}
					$(".user-registration-search-icon").show();
				}
			});
		},
		classes: {
			"ui-autocomplete": "user-registration-ui-autocomplete"
		},
		minLength: 3, // Minimum characters required to trigger autocomplete
		focus: function (event, ui) {
			$(".user-registration-ui-autocomplete > li").attr(
				"title",
				ui.item.desc
			);
			$("#ur-search-settings").val(ui.item.label);
			return false;
		},
		select: function (event, ui) {
			// Update the input field value with the selected value
			if ("no_result_found" !== ui.item.value) {
				$(".user-registration #ur-search-settings").val(ui.item.label);
				// Redirect the user to the selected URL
				window.location.href = ui.item.value;
			}
			return false; // Prevent the default behavior of the widget
		}
	});

	// Display error when page with our my account or login shortcode is not selected
	$("#user_registration_myaccount_page_id").on("change", function () {
		var $this = $(this),
			data = {
				action: "user_registration_my_account_selection_validator",
				security:
					user_registration_settings_params.user_registration_my_account_selection_validator_nonce
			};

		data.user_registration_selected_my_account_page = $this.val();

		$this.prop("disabled", true);
		$this.css("border", "1px solid #e1e1e1");
		$this
			.closest(".user-registration-global-settings--field")
			.find(".error.inline")
			.remove();
		$this
			.closest(".user-registration-global-settings")
			.append('<div class="ur-spinner is-active"></div>');

		$.ajax({
			url: user_registration_settings_params.ajax_url,
			data: data,
			type: "POST",
			complete: function (response) {
				if (response.responseJSON.success === false) {
					$this
						.closest(".user-registration-global-settings--field")
						.append(
							"<div id='message' class='error inline' style='padding:10px;'>" +
								response.responseJSON.data.message +
								"</div>"
						);
					$this.css("border", "1px solid red");
					$this
						.closest("form")
						.find("input[name='save']")
						.prop("disabled", true);
				} else {
					$this
						.closest("form")
						.find("input[name='save']")
						.prop("disabled", false);
					$this
						.closest(".user-registration-global-settings")
						.find(".error inline")
						.remove();
				}
				$this.prop("disabled", false);

				$this
					.closest(".user-registration-global-settings")
					.find(".ur-spinner")
					.remove();
			}
		});
	});

	$("#user_registration_login_page_id").on("change", function () {
		var $this = $(this),
			data = {
				action: "user_registration_my_account_selection_validator",
				security:
					user_registration_settings_params.user_registration_my_account_selection_validator_nonce
			};

		data.user_registration_selected_my_account_page = $this.val();

		$this.prop("disabled", true);
		$this.css("border", "1px solid #e1e1e1");
		$this
			.closest(".user-registration-global-settings--field")
			.find(".error.inline")
			.remove();
		$this
			.closest(".user-registration-global-settings")
			.append('<div class="ur-spinner is-active"></div>');

		$.ajax({
			url: user_registration_settings_params.ajax_url,
			data: data,
			type: "POST",
			complete: function (response) {
				if (response.responseJSON.success === false) {
					$this
						.closest(".user-registration-global-settings--field")
						.append(
							"<div id='message' class='error inline' style='padding:10px;'>" +
								response.responseJSON.data.message +
								"</div>"
						);
					$this.css("border", "1px solid red");
					$this
						.closest("form")
						.find("input[name='save']")
						.prop("disabled", true);
				} else {
					$this
						.closest("form")
						.find("input[name='save']")
						.prop("disabled", false);
					$this
						.closest(".user-registration-global-settings")
						.find(".error inline")
						.remove();
				}
				$this.prop("disabled", false);

				$this
					.closest(".user-registration-global-settings")
					.find(".ur-spinner")
					.remove();
			}
		});
	});

	// Display error when page with our lost password shortcode is not selected.
	$("#user_registration_lost_password_page_id").on("change", function () {
		var $this = $(this),
			data = {
				action: "user_registration_lost_password_selection_validator",
				security:
					user_registration_settings_params.user_registration_lost_password_selection_validator_nonce
			};

		data.user_registration_selected_lost_password_page = $this.val();

		$this.css("border", "1px solid #e1e1e1");

		$this
			.closest(".user-registration-global-settings--field")
			.find(".error.inline")
			.remove();
		$this
			.closest(".user-registration-global-settings")
			.append('<div class="ur-spinner is-active"></div>');

		$.ajax({
			url: user_registration_settings_params.ajax_url,
			data: data,
			type: "POST",
			complete: function (response) {
				$this
					.closest(".user-registration-global-settings")
					.find(".ur-spinner")
					.remove();
				if (response.responseJSON.success === false) {
					if (
						$this
							.closest(
								".user-registration-global-settings--field"
							)
							.find(".error.inline").length === 0
					) {
						$this
							.closest(
								".user-registration-global-settings--field"
							)
							.append(
								"<div id='message' class='error inline' style='padding:10px;'>" +
									response.responseJSON.data.message +
									"</div>"
							);
					}
					$this.css("border", "1px solid red");
					$('input[name="save"]').prop("disabled", true);
				} else {
					$this.css("border", "1px solid #e1e1e1");
					$this
						.closest(
							".user-registration-global-settings--field"
						)
						.find(".error.inline")
						.remove();
					if ($(".error.inline").length === 0) {
						$('input[name="save"]').prop("disabled", false);
					}
				}
			}
		});
	});

	// Set localStorage with expiry
	function setStorageValue(key, value) {
		var current = new Date();

		var data = {
			value: value,
			expiry: current.getTime() + 86400000 // 1day of expiry time
		};

		localStorage.setItem(key, JSON.stringify(data));
	}

	// Get localStorage with expiry
	function getStorageValue(key) {
		var item = localStorage.getItem(key);

		if (!item) {
			return false;
		}

		var data = JSON.parse(item);
		var current = new Date();

		if (current.getTime() > data.expiry) {
			localStorage.removeItem(key);
			return false;
		}
		return true;
	}

	// Handles collapse of side menu.
	$("#ur-settings-collapse").on("click", function (e) {
		e.preventDefault();

		if ($(this).hasClass("close")) {
			$(this).closest("header").addClass("collapsed");
			$(this).removeClass("close").addClass("open");
			setStorageValue("ur-settings-navCollapsed", true); // set to localStorage
		} else {
			$(this).closest("header").removeClass("collapsed");
			$(this).removeClass("open").addClass("close");
			localStorage.removeItem("ur-settings-navCollapsed"); // remove from localStorage
		}
	});

	// Persist the collapsable state through page reload
	var isNavCollapsed =
		getStorageValue("ur-settings-navCollapsed") === true
			? "collapsed"
			: "not-collapsed";
	if (isNavCollapsed == "collapsed") {
		$(".user-registration-header").addClass("collapsed");
		$("#ur-settings-collapse").removeClass("close").addClass("open");
	} else {
		$(".user-registration-header").removeClass("collapsed");
		$("#ur-settings-collapse").removeClass("open").addClass("close");
	}

	$(".ur-nav-premium").each(function () {
		$(this).hover(
			function (e) {
				$(this).find(".ur-tooltip").show();
			},
			function (e) {
				$(this).find(".ur-tooltip").hide();
			}
		);
	});
	/**
	 * Open collapsed menu on search input clicked.
	 */
	$(".ur-search-input").on("click", function () {
		if (
			$(this).closest(".user-registration-header").hasClass("collapsed")
		) {
			$(this)
				.closest(".user-registration-header")
				.removeClass("collapsed");
			$(this)
				.closest(".user-registration-header")
				.find("#ur-settings-collapse")
				.addClass("close");
			$(this).find("#ur-search-settings").focus();
		}
	});

	if (
		typeof getUrlVars()["searched_option"] != "undefined" ||
		getUrlVars()["searched_option"] != null
	) {
		var $searched_id = $("#" + getUrlVars()["searched_option"]);
		var wrapper_div = $searched_id.closest(
			".user-registration-global-settings"
		);
		wrapper_div.addClass("ur-searched-settings-focus");

		var offset = $(".ur-searched-settings-focus").offset().top;
		window.scrollTo({
			top: offset - 300,
			behavior: "smooth"
		});
		setTimeout(function () {
			wrapper_div.removeClass("ur-searched-settings-focus");
		}, 2000);
	}

	/**
	 * Get Query String.
	 *
	 * @returns
	 */
	function getUrlVars() {
		var vars = [],
			hash;
		var hashes = window.location.href
			.slice(window.location.href.indexOf("?") + 1)
			.split("&");
		for (var i = 0; i < hashes.length; i++) {
			hash = hashes[i].split("=");
			vars.push(hash[0]);
			vars[hash[0]] = hash[1];
		}
		return vars;
	}

	/**
	 * Display the upgrade message for the top addons.
	 */
	$("body").on("click", ".user-registration-inactive-addon", function (e) {
		$this = $(this);
		e.preventDefault();
		var video_id = $this.data("video");
		var plugin_title = $this.data("title");
		var available_in = $(this).data("available-in");
		var video = "";

		if (video_id !== "") {
			video =
				'<div style="width: 535px; height: 300px;"><iframe width="100%" height="100%" frameborder="0" src="https://www.youtube.com/embed/' +
				video_id +
				'" rel="1" allowfullscreen></iframe></div><br>';
		}
		var icon =
			'<i class="dashicons dashicons-lock" style="color:#475bb2; border-color: #475bb2;"></i>';

		var message =
			video + user_registration_settings_params.i18n.upgrade_message;

		message = message
			.replace("%title%", plugin_title)
			.replace("%plan%", available_in);

		var title =
			icon +
			'<span class="user-registration-swal2-modal__title">' +
			plugin_title +
			" " +
			user_registration_settings_params.i18n.pro_feature_title;
		("</span>");
		Swal.fire({
			title: title,
			html: message,
			customClass:
				"user-registration-swal2-modal user-registration-swal2-modal--centered user-registration-locked-field",
			showCloseButton: true,
			showConfirmButton: true,
			allowOutsideClick: true,
			heightAuto: false,
			width: "575px",
			confirmButtonText:
				user_registration_settings_params.i18n.upgrade_plan
		}).then(function (result) {
			if (result.isConfirmed) {
				window.open(
					user_registration_settings_params.i18n.upgrade_link,
					"_blank"
				);
			}
		});
	});

	$(document)
		.find(".user-registration-global-settings--field")
		.find(".ur-radio-group-list--item")
		.each(function () {
			$(this).on("click", function () {
				$(this)
					.closest(".ur-radio-group-list")
					.find(".active")
					.find("input")
					.prop("checked", false);
				$(this)
					.closest(".ur-radio-group-list")
					.find(".active")
					.removeClass("active");
				$(this).addClass("active");
				$(this).find("input").prop("checked", true);
			});
		});

	// Function to handle changes in the premium sidebar.
	$(document).ready(function () {
		function handleSettingsSidebar(node) {
			var isCheckboxChecked = $(node).is(":checked");

			localStorage.setItem("isSidebarEnabled", isCheckboxChecked);

			var isSecure = window.location.protocol === "https:";
			var cookieAttributes =
				"; path=/" +
				(isSecure ? "; Secure" : "") + // Secure only over HTTPS
				"; SameSite=" +
				(isSecure ? "Strict" : "Lax"); // Strict on prod, Lax on dev

			document.cookie =
				"isSidebarEnabled=" + isCheckboxChecked + cookieAttributes;

			if (isCheckboxChecked) {
				$("body")
					.removeClass("ur-settings-sidebar-hidden")
					.addClass("ur-settings-sidebar-show");
				$(".user-registration-settings-sidebar-container").removeClass(
					"ur-d-none"
				);
				$(node)
					.closest(".user-registration-options-header--top__right")
					.find(".user-registration-toggle-text")
					.text("Sidebar");
			} else {
				$("body")
					.removeClass("ur-settings-sidebar-show")
					.addClass("ur-settings-sidebar-hidden");
				$(node)
					.closest(".user-registration-options-header--top__right")
					.find(".user-registration-toggle-text")
					.text("Sidebar");
			}
		}

		$(document).on(
			"change",
			"#user_registration_hide_show_sidebar",
			function (e) {
				handleSettingsSidebar($(this));
			}
		);

		init_accordion_settings();
		highlight_deep_linked_setting();
	});

	// Deep-link support: ?highlight=<field_id> scrolls to and flashes that setting row. Tab/section routing is the link's own job (e.g. &tab=advanced&section=others); this only handles the in-page scroll/flash once that page has loaded.
	function highlight_deep_linked_setting() {
		var params = new URLSearchParams(window.location.search);
		var field_id = params.get("highlight");

		if (!field_id) {
			return;
		}

		var $field = $("#" + field_id);

		if (!$field.length) {
			return;
		}

		var $target = $field.closest(".user-registration-global-settings");
		$target = $target.length ? $target : $field;

		setTimeout(function () {
			$target[0].scrollIntoView({ behavior: "smooth", block: "center" });

			var original_outline = $target.css("outline");
			var original_outline_offset = $target.css("outline-offset");
			var original_border_radius = $target.css("border-radius");
			$target.css({
				transition: "outline-color 0.3s ease",
				outline: "2px solid #475bb2",
				"outline-offset": "8px",
				"border-radius": "2px",
			});

			setTimeout(function () {
				$target.css("outline-color", "transparent");

				setTimeout(function () {
					$target.css({
						outline: original_outline,
						"outline-offset": original_outline_offset,
						"border-radius": original_border_radius,
					});
				}, 300);
			}, 1800);
		}, 300);
	}

	/**
	 * Initialize accordion_settings elements.
	 */
	function init_accordion_settings() {
		var acc = document.getElementsByClassName("accordion");
		var i;
		for (i = 0; i < acc.length; i++) {
			var panel = acc[i].nextElementSibling;
			panel.style.display = "none";

			acc[i].addEventListener("click", function () {
				/* Toggle between adding and removing the "active" class,
			to highlight the button that controls the panel */
				this.classList.toggle("active");

				/* Toggle between hiding and showing the active panel */
				var panel = this.nextElementSibling;
				if (panel.style.display === "block") {
					panel.style.display = "none";
				} else {
					panel.style.display = "block";
				}
			});
		}

		$.each($(".ur-captcha-settings"), function () {
			var is_enabled = $(this)
				.find(".ur-captcha-settings-body .ur-captcha-enable")
				.is(":checked");
			if (is_enabled) {
				$(this)
					.find(".ur-captcha-settings-header .ur-connection-status")
					.addClass("ur-connection-status--active");
			}
		});
	}

	/**
	 * Disable leave page before saving changes modal when hid/show sidebar is clicked.
	 */
	function disableFormChangeModal() {
		var form = $(".user-registration").find("form")[0];

		var formChanged = false;

		$(form).on("change", function (event) {
			if (event.target.name !== "user_registration_enable_sidebar") {
				formChanged = true;
			}
		});

		var skipBeforeUnloadPopup = false;
		$(form).on("submit", function () {
			skipBeforeUnloadPopup = true;
		});

		$(form)
			.find(".ur-nav__link")
			.on("click", function () {
				skipBeforeUnloadPopup = true;
			});

		$(window).on("beforeunload", function (event) {
			if (formChanged && !skipBeforeUnloadPopup) {
				event.preventDefault();
				event.returnValue = "";
			} else {
				event.stopImmediatePropagation();
			}
		});
	}

	/**
	 * Show success message using snackbar.
	 *
	 * @param {String} message Message to show.
	 */
	function show_success_message(message) {
		if (snackbar) {
			snackbar.add({
				type: "success",
				message: message,
				duration: 5
			});
			return true;
		}
		return false;
	}

	/**
	 * Show failure message using snackbar.
	 *
	 * @param {String} message Message to show.
	 */
	function show_failure_message(message) {
		if (snackbar) {
			snackbar.add({
				type: "failure",
				message: message,
				duration: 6,
				dismissible: true
			});
			return true;
		}
		return false;
	}

	function update_payment_section_settings(
		setting_id,
		section_data,
		$this,
		settings_container
	) {
		$.ajax({
			url: user_registration_settings_params.ajax_url,
			data: {
				action: "user_registration_save_payment_settings",
				security:
					user_registration_settings_params.user_registration_membership_payment_settings_nonce,
				setting_id: setting_id,
				section_data: JSON.stringify(section_data)
			},
			type: "POST",
			complete: function (response) {
				$this.find(".ur-spinner").remove();
				if (response.responseJSON.success) {
					show_success_message(response.responseJSON.data.message);
					if (response.responseJSON.data.is_connected) {
						settings_container
							.find(".ur-connection-status")
							.addClass("ur-connection-status--active");
						settings_container
							.find(".reset-payment-keys")
							.removeClass("ur-d-none");
					} else {
						settings_container
							.find(".ur-connection-status")
							.removeClass("ur-connection-status--active");
					}
				} else {
					show_failure_message(response.responseJSON.data.message);
				}
			}
		});
	}

	function update_captcha_section_settings(
		setting_id,
		section_data,
		$this,
		settings_container
	) {
		$.ajax({
			url: user_registration_settings_params.ajax_url,
			data: {
				action: "user_registration_save_captcha_settings",
				security:
					user_registration_settings_params.user_registration_membership_captcha_settings_nonce,
				setting_id: setting_id,
				section_data: JSON.stringify(section_data)
			},
			type: "POST",
			success: function (response) {
				if (response.success) {
					var successMessage = response.data.message;
					settings_container
						.find(".ur-connection-status")
						.addClass("ur-connection-status--active");
					settings_container
						.find(".reset-captcha-keys")
						.removeClass("ur-d-none");
					$this.find(".ur-spinner").remove();
					show_success_message(successMessage);
				} else {
					$this.find(".ur-spinner").remove();
					show_failure_message(response.data.message);
				}
			}
		});
	}

	$(document)
		.find(".wp-list-table")
		.wrap("<div class='ur-list-table-wrapper'></div>");

	$(
		"#user_registration_member_registration_page_id, #user_registration_thank_you_page_id"
	).on("change", function () {
		var $this = $(this),
			type = $this.attr("id"),
			val = $(this).val();
		// $this.prop("disabled", true);

		$this
			.closest(".user-registration-global-settings--field")
			.find(".error.inline")
			.remove();
		$this
			.closest(".user-registration-global-settings")
			.find(".ur-spinner")
			.remove();
		$this
			.closest(".user-registration-global-settings")
			.append('<div class="ur-spinner is-active"></div>');

		$.ajax({
			url: user_registration_settings_params.ajax_url,
			data: {
				action: "user_registration_membership_verify_pages",
				type: type,
				value: val,
				security:
					user_registration_settings_params.user_registration_membership_pages_selection_validator_nonce
			},
			type: "POST",
			complete: function (response) {
				if (response.responseJSON.status === false) {
					var disableSaveBtn = response.responseJSON.disable_save_btn;
					var className = "error";
					var inlineStyle = "padding:10px;";

					if (typeof disableSaveBtn === "undefined") {
						$this
							.closest("form")
							.find("input[name='save']")
							.prop("disabled", true);
					} else if (disableSaveBtn === "no") {
						className += " settings-page-notice";
						$this
							.closest("form")
							.find("input[name='save']")
							.prop("disabled", false);
					}

					$this
						.closest(".user-registration-global-settings--field")
						.append(
							"<div id='message' class='" +
								className +
								" inline' style='" +
								inlineStyle +
								"'>" +
								response.responseJSON.message +
								"</div>"
						);
				} else {
					if (
						$this
							.closest(".user-registration-options-container")
							.find(".error.inline").length
					) {
						$this
							.closest("form")
							.find("input[name='save']")
							.prop("disabled", true);
					} else {
						$this
							.closest("form")
							.find("input[name='save']")
							.prop("disabled", false);
					}
				}
				$this.prop("disabled", false);

				$this
					.closest(".user-registration-global-settings")
					.find(".ur-spinner")
					.remove();
			}
		});
	});

	$(".payment-settings-btn").on("click", function () {
		var $this = $(this),
			setting_id = $this.data("id"),
			settings_container = $this.closest("#" + setting_id);

		if ($this.find(".ur-spinner").length > 0) {
			return;
		}
		$this.append("<span class='ur-spinner'></span>");

		var section_data = {};

		$(
			"input, select, textarea",
			settings_container[0] || settings_container
		).each(function (key, item) {
			var $item = $(item);
			var name = $item.attr("name");
			if (!name) return;

			var value;
			if ($item.attr("type") === "checkbox") {
				value = $item.is(":checked");
			} else if ($item.attr("type") === "radio") {
				if (!$item.is(":checked")) return;
				value = $item.val();
			} else if (
				$item.is("textarea") &&
				typeof tinymce !== "undefined" &&
				tinymce.get(name)
			) {
				value = tinymce.get(name).getContent();
			} else {
				value = $item.val();
			}
			section_data[name] = value;
		});

		if ( setting_id === "stripe" ) {
			var testPubKey = section_data["user_registration_stripe_test_publishable_key"] || "";
			var livePubKey = section_data["user_registration_stripe_live_publishable_key"] || "";

			if ( testPubKey && testPubKey.indexOf( "pk_test_" ) !== 0 ) {
				$this.find( ".ur-spinner" ).remove();
				show_failure_message(
					user_registration_settings_params.i18n.invalid_stripe_test_publishable_key
				);
				return;
			}

			if ( livePubKey && livePubKey.indexOf( "pk_live_" ) !== 0 ) {
				$this.find( ".ur-spinner" ).remove();
				show_failure_message(
					user_registration_settings_params.i18n.invalid_stripe_live_publishable_key
				);
				return;
			}
		}

		update_payment_section_settings(
			setting_id,
			section_data,
			$this,
			settings_container
		);
	});
	$("#user_registration_payment_currency").on("change", function () {
		var $this = $(this);
		var currency = $this.val();
		$this
			.closest(".user-registration-global-settings--field")
			.find(".error.inline")
			.remove();
		$this
			.closest(".user-registration-global-settings")
			.append('<div class="ur-spinner is-active"></div>');

		$.ajax({
			url: user_registration_settings_params.ajax_url,
			data: {
				action: "user_registration_validate_payment_currency",
				security:
					user_registration_settings_params.user_registration_membership_validate_payment_currency_nonce,
				currency: currency
			},
			type: "POST",
			complete: function (response) {
				if (response.responseJSON.success === false) {
					$this
						.closest(".user-registration-global-settings")
						.find(".warning")
						.remove();
					$this
						.closest(".user-registration-global-settings--field")
						.append(
							"<div id='message' class='warning inline' style='padding:10px;'>" +
								response.responseJSON.data.message +
								"</div>"
						);
				} else {
					$this
						.closest(".user-registration-global-settings")
						.find(".warning")
						.remove();
				}
				$this.prop("disabled", false);
				$this
					.closest(".user-registration-global-settings")
					.find(".ur-spinner")
					.remove();
			}
		});
	});
	$("#user_registration_payment_currency").trigger("change");

	$(document).on(
		"click",
		".urm_license_setting_notice .install_pro_version_button",
		function () {
			$(this)
				.prop("disabled", true)
				.text(
					user_registration_settings_params.i18n
						.installing_plugin_text
				)
				.prepend(
					'<div class="ur-spinner is-active" style="margin-right: 8px;"></div>'
				);
			var data = {
				action: "user_registration_install_extension",
				slug: "user-registration-pro",
				_ajax_nonce: user_registration_settings_params.ur_updater_nonce
			};
			$.ajax({
				type: "POST",
				url: user_registration_settings_params.ajax_url,
				data: data,
				success: function (response) {
					if (response.success) {
						window.location.href =
							window.location.href +
							"&download_user_registration_pro=1";
					} else {
						$(".install_pro_version_button").prop(
							"disabled",
							false
						);
						$(".install_pro_version_button")
							.find(".ur-spinner")
							.remove();
					}
				},
				error: function (response) {
					$(".install_pro_version_button").prop("disabled", false);
					$(".install_pro_version_button")
						.find(".ur-spinner")
						.remove();
				}
			});
		}
	);

	var searchParams = new URLSearchParams(window.location.search);

	var license_activation_status = ur_get_cookie("urm_license_status");
	if (
		searchParams.get("activated_license") == "user-registration" &&
		license_activation_status === "license_activated"
	) {
		ur_remove_cookie("urm_license_status");
		var urmProInstallHtml =
			'<div style="display: flex; align-items: center; width: 60%;margin: 0px auto; position: relative;">' +
			'<img src="' +
			user_registration_settings_params.assets_url +
			'/images/logo.png" alt="URM Logo" width="50" style="margin: 0 20px;" />' +
			'<img src="' +
			user_registration_settings_params.assets_url +
			'/images/connect.gif" alt="Connect gif" >' +
			'<img src="' +
			user_registration_settings_params.assets_url +
			'/images/wordpress.png" ' +
			'alt="WordPress Logo" width="50" style="margin: 0 10px 0 30px;" />' +
			"</div>" +
			'<p style="margin-bottom: 20px;font-size:13px;text-align:center;">' +
			user_registration_settings_params.i18n.license_activated_text +
			"</p>" +
			'<form method="post" class="ur-install-urm-pro">' +
			'<input type="hidden" name="download_user_registration_pro" value="1" />' +
			'<input type="hidden" name="ur_license_nonce" value="' +
			user_registration_settings_params.ur_license_nonce +
			'" />' +
			'<button id="install-urm-pro-btn" class="swal2-confirm button button-primary" style="margin-bottom: 20px;">' +
			user_registration_settings_params.i18n.pro_install_popup_button +
			"</button>" +
			"</form>" +
			'<p class="ur-install-urm-pro-p-tag" style="font-size:13px;text-align:center;">' +
			user_registration_settings_params.i18n
				.will_install_and_activate_pro_text +
			"</p>";
		Swal.fire({
			title: user_registration_settings_params.i18n
				.pro_install_popup_title,
			html: urmProInstallHtml,
			showConfirmButton: false,
			showCloseButton: true,
			allowOutsideClick: false,
			customClass:
				"user-registration-swal2-modal user-registration-swal2-modal--centered user-registration-swal2-modal--install-urm-pro",
			width: 640,
			didOpen: function () {
				$("#install-urm-pro-btn").on("click", function () {
					$(this)
						.prop("disabled", true)
						.text(
							user_registration_settings_params.i18n
								.installing_plugin_text
						)
						.prepend(
							'<div class="ur-spinner is-active" style="margin-right: 8px;"></div>'
						);
					$(this).closest("form").submit();
				});
			}
		});
	}
	if (
		searchParams.get("activated_license") == "user-registration" &&
		license_activation_status === "pro_activated"
	) {
		ur_remove_cookie("urm_license_status");
		var $successModalHtml =
			'<p style="margin: 10px 0 20px;">' +
			user_registration_settings_params.i18n.pro_activated_success_text +
			"</p>" +
			'<button id="dashboard-redirect-btn" style="' +
			"background: transparent;" +
			"border: 1px solid #475bb2;" +
			"color: #475bb2;" +
			"padding: 8px 16px;" +
			"margin-bottom: 16px;" +
			"border-radius: 6px;" +
			"font-size: 14px;" +
			"cursor: pointer;" +
			'">' +
			user_registration_settings_params.i18n.continue_to_dashboard_text +
			"</button>";
		var $successIcon =
			'<svg width="28" height="28" viewBox="0 0 28 28" fill="none" style="flex-shrink:0" xmlns="http://www.w3.org/2000/svg">' +
			'<circle cx="14" cy="14" r="13" stroke="#a5dc86" stroke-width="2"/>' +
			'<path d="M8 14l4 4 8-8" stroke="#a5dc86" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>' +
			"</svg>";
		Swal.fire({
			title:
				$successIcon +
				user_registration_settings_params.i18n.pro_activated_success_title,
			html: $successModalHtml,
			showConfirmButton: false,
			showCloseButton: true,
			customClass:
				"user-registration-swal2-modal user-registration user-registration-swal2-modal--center swal2-show",
			width: 400,
			didOpen: function () {
				$("#dashboard-redirect-btn").on("click", function () {
					window.location.href =
						"/wp-admin/admin.php?page=user-registration-dashboard";
				});
			}
		});
	}

	var searchParams = new URLSearchParams(window.location.search);
	if (
		searchParams.has("method") &&
		searchParams.get("method") !== "" &&
		$(".user-registration-settings-container").find(
			"#" + searchParams.get("method")
		).length > 0
	) {
		var container = $(".user-registration-settings-container").find(
			"#" + searchParams.get("method")
		);
		setTimeout(function () {
			container.find(".integration-header-info").trigger("click");
		}, 400);
	}
	$(".captcha-save-btn").on("click", function () {
		var $this = $(this),
			setting_id = $this.data("id"),
			settings_container = $this.closest("#" + setting_id);

		if ($this.find(".ur-spinner").length > 0) {
			return;
		}
		$this.append("<span class='ur-spinner'></span>");
		var section_data = urm_get_captcha_section_data(settings_container);
		update_captcha_section_settings(
			setting_id,
			section_data,
			$this,
			settings_container
		);
	});
	$(".reset-captcha-keys").on("click", function () {
		var $this = $(this);
		Swal.fire({
			title:
				'<img src="' +
				user_registration_settings_params.reset_keys_icon +
				'">' +
				user_registration_settings_params.i18n.captcha_reset_title,
			html:
				'<p id="html_1">' +
				user_registration_settings_params.i18n.captcha_reset_prompt +
				"</p>",
			showCancelButton: true,
			confirmButtonText:
				user_registration_settings_params.i18n.i18n_prompt_reset,
			cancelButtonText:
				user_registration_settings_params.i18n.i18n_prompt_cancel,
			allowOutsideClick: false,
			preConfirm: function () {
				var btn = $(".swal2-confirm");
				if (btn.find(".ur-spinner").length > 0) {
					return;
				}
				btn.append('<span class="ur-spinner"></span>');
				reset_captcha_keys($this, btn);
				return false;
			}
		});
	});

	$(".reset-payment-keys").on("click", function () {
		var $this = $(this);
		Swal.fire({
			title:
				'<img src="' +
				user_registration_settings_params.reset_keys_icon +
				'">' +
				user_registration_settings_params.i18n.payment_reset_title,
			html:
				'<p id="html_1">' +
				user_registration_settings_params.i18n.payment_reset_prompt +
				"</p>",
			showCancelButton: true,
			confirmButtonText:
				user_registration_settings_params.i18n.i18n_prompt_reset,
			cancelButtonText:
				user_registration_settings_params.i18n.i18n_prompt_cancel,
			allowOutsideClick: false,
			preConfirm: function () {
				var btn = $(".swal2-confirm");
				if (btn.find(".ur-spinner").length > 0) {
					return;
				}
				btn.append('<span class="ur-spinner"></span>');
				reset_payment_keys($this, btn);
				return false;
			}
		});
	});

	function urm_get_captcha_section_data(settings_container) {
		var section_data = {};
		settings_container
			.find("input, select, textarea")
			.each(function (key, item) {
				var $item = $(item);
				var name = $item.attr("name");
				if (!name) return;

				var value;
				if ($item.attr("type") === "checkbox") {
					value = $item.is(":checked");
				} else if (
					$item.is("textarea") &&
					typeof tinymce !== "undefined" &&
					tinymce.get(name)
				) {
					value = tinymce.get(name).getContent();
				} else {
					value = $item.val();
				}
				section_data[name] = value;
			});
		return section_data;
	}

	function reset_captcha_keys($this, btn) {
		var setting_id = $this.data("id"),
			settings_container = $this.closest("#" + setting_id);
		$.ajax({
			url: user_registration_settings_params.ajax_url,
			data: {
				action: "user_registration_reset_captcha_keys",
				security:
					user_registration_settings_params.user_registration_membership_captcha_settings_nonce,
				setting_id: setting_id
			},
			type: "POST",
			success: function (response) {
				if (response.success) {
					show_success_message(
						response.data.message ||
							user_registration_settings_params.i18n
								.captcha_keys_reset_success
					);
					settings_container
						.find(".ur-connection-status")
						.removeClass("ur-connection-status--active");
					settings_container.find('input[type="text"]').val("");

					// Remove captcha node after successful reset
					var urm_recaptcha_node = $(
						'.ur-captcha-test-container[data-captcha-type="' +
							setting_id +
							'"] .ur-captcha-node'
					);

					if (urm_recaptcha_node.length !== 0) {
						// Remove captcha widgets
						urm_recaptcha_node
							.find(
								".g-recaptcha, .g-recaptcha-hcaptcha, .cf-turnstile"
							)
							.remove();
						urm_recaptcha_node
							.find("[data-rendered]")
							.removeAttr("data-rendered");
					}

					// Hide reset button after successful reset
					$this.addClass("ur-d-none");
				} else {
					show_failure_message(
						response.data.message ||
							user_registration_settings_params.i18n
								.captcha_keys_reset_error
					);
				}
			},
			error: function (xhr, status, error) {
				var errorMessage =
					error ||
					user_registration_settings_params.i18n
						.captcha_keys_reset_error;
				show_failure_message(errorMessage);
				reject({ data: { message: errorMessage } });
			},
			complete: function (response) {
				btn.find(".ur-spinner").remove();
				Swal.close();
			}
		});
	}

	function reset_payment_keys($this, btn) {
		var setting_id = $this.data("id"),
			settings_container = $this.closest("#" + setting_id);
		$.ajax({
			url: user_registration_settings_params.ajax_url,
			data: {
				action: "user_registration_reset_payment_keys",
				security:
					user_registration_settings_params.user_registration_membership_payment_settings_nonce,
				setting_id: setting_id
			},
			type: "POST",
			success: function (response) {
				if (response.success) {
					show_success_message(
						response.data.message ||
							user_registration_settings_params.i18n
								.payment_keys_reset_success
					);
					settings_container
						.find(".ur-connection-status")
						.removeClass("ur-connection-status--active");
					settings_container.find('input[type="text"]').val("");
					settings_container
						.find("input[type='checkbox']")
						.prop("checked", false);

					settings_container.find("textarea").each(function () {
						let editor = $(this).attr("id");
						if (editor && tinymce.get(editor)) {
							tinymce.get(editor).setContent("");
						}
					});
					// Hide reset button after successful reset
					$this.addClass("ur-d-none");
				} else {
					show_failure_message(
						response.data.message ||
							user_registration_settings_params.i18n
								.payment_keys_reset_error
					);
				}
			},
			error: function (xhr, status, error) {
				var errorMessage =
					error ||
					user_registration_settings_params.i18n
						.payment_keys_reset_error;
				show_failure_message(errorMessage);
				reject({ data: { message: errorMessage } });
			},
			complete: function (response) {
				btn.find(".ur-spinner").remove();
				Swal.close();
			}
		});
	}

	/**
	 * Retrieves the cookie values set.
	 */
	function ur_get_cookie(cookie_key) {
		var matches = document.cookie.match(
			new RegExp(
				"(?:^|; )" +
					cookie_key.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, "\\$1") +
					"=([^;]*)"
			)
		);
		return matches ? decodeURIComponent(matches[1]) : undefined;
	}

	/**
	 * Deletes the cookie values.
	 */
	function ur_remove_cookie(cookie_key) {
		document.cookie =
			cookie_key +
			"=; Max-Age=-99999999; path=/" +
			(window.location.protocol === "https:" ? "; Secure" : "") +
			"; SameSite=Strict";
	}

	$(document).on(
		"click",
		".user-registration-options-header__burger",
		function () {
			$(".user-registration-header").addClass(
				"user-registration-header--open"
			);
			$(".user-registration-settings-container").addClass(
				"user-registration-settings-container--dimmed"
			);
			$(this).addClass(".user-registration-header__burger--hidden");
			$(".user-registration-header__close").removeClass(
				"user-registration-header__close--hidden"
			);
		}
	);
	$(document).on("click", ".user-registration-header__close", function () {
		$(".user-registration-header").removeClass(
			"user-registration-header--open"
		);
		$(".user-registration-settings-container").removeClass(
			"user-registration-settings-container--dimmed"
		);
		$(this).addClass("user-registration-header__close--hidden");
		$(".user-registration-options-header__burger").removeClass(
			"user-registration-header__close--hidden"
		);
	});

	/**
	 * Handle display conditions/dependencies for settings fields.
	 */
	function handleDisplayConditions() {
		// Find all fields with display conditions.
		$('[data-has-display-condition="1"]').each(function () {
			var $field = $(this);
			var conditionField = $field.data("display-condition-field");
			var operator =
				$field.data("display-condition-operator") || "equals";
			var conditionValue = $field.data("display-condition-value");
			var caseSensitive =
				$field.data("display-condition-case") || "insensitive";

			if (!conditionField) {
				return;
			}

			// Parse condition value if it's JSON (for arrays).
			if (
				typeof conditionValue === "string" &&
				conditionValue.startsWith("[")
			) {
				try {
					conditionValue = JSON.parse(conditionValue);
				} catch (e) {
					// If parsing fails, use as string.
				}
			}

			// Function to check condition and show/hide field.
			var checkCondition = function (useAnimation) {
				var $conditionField = $("#" + conditionField);
				var fieldValue = "";

				// Get field value based on field type.
				if ($conditionField.length === 0) {
					return;
				}

				if ($conditionField.is(":checkbox")) {
					fieldValue = $conditionField.is(":checked") ? "yes" : "no";
				} else if ($conditionField.is(":radio")) {
					fieldValue = $conditionField.filter(":checked").val() || "";
				} else if ($conditionField.is("select")) {
					fieldValue = $conditionField.val() || "";
					// Handle multiselect.
					if ($conditionField.attr("multiple")) {
						fieldValue = $conditionField.val() || [];
					}
				} else {
					fieldValue = $conditionField.val() || "";
				}

				// Convert fieldValue to string for comparison if needed.
				var fieldValueStr = Array.isArray(fieldValue)
					? fieldValue.join(",")
					: String(fieldValue);
				var conditionValueStr = Array.isArray(conditionValue)
					? conditionValue.join(",")
					: String(conditionValue);

				// Case sensitivity handling.
				if (
					caseSensitive === "insensitive" ||
					caseSensitive === "false"
				) {
					fieldValueStr = fieldValueStr.toLowerCase();
					conditionValueStr = conditionValueStr.toLowerCase();
				}

				var shouldShow = false;

				// Evaluate condition based on operator.
				switch (operator) {
					case "equals":
					case "==":
						shouldShow = fieldValueStr === conditionValueStr;
						break;
					case "not_equals":
					case "!=":
						shouldShow = fieldValueStr !== conditionValueStr;
						break;
					case "contains":
						shouldShow =
							fieldValueStr.indexOf(conditionValueStr) !== -1;
						break;
					case "not_contains":
						shouldShow =
							fieldValueStr.indexOf(conditionValueStr) === -1;
						break;
					case "empty":
						shouldShow =
							!fieldValue ||
							fieldValueStr === "" ||
							(Array.isArray(fieldValue) &&
								fieldValue.length === 0);
						break;
					case "not_empty":
						shouldShow =
							fieldValue &&
							fieldValueStr !== "" &&
							!(
								Array.isArray(fieldValue) &&
								fieldValue.length === 0
							);
						break;
					case "greater_than":
					case ">":
						shouldShow =
							parseFloat(fieldValue) > parseFloat(conditionValue);
						break;
					case "less_than":
					case "<":
						shouldShow =
							parseFloat(fieldValue) < parseFloat(conditionValue);
						break;
					case "greater_than_or_equal":
					case ">=":
						shouldShow =
							parseFloat(fieldValue) >=
							parseFloat(conditionValue);
						break;
					case "less_than_or_equal":
					case "<=":
						shouldShow =
							parseFloat(fieldValue) <=
							parseFloat(conditionValue);
						break;
					case "in":
						if (Array.isArray(conditionValue)) {
							shouldShow =
								conditionValue.indexOf(fieldValue) !== -1 ||
								conditionValue.indexOf(fieldValueStr) !== -1;
						} else {
							shouldShow =
								String(conditionValue)
									.split(",")
									.indexOf(fieldValueStr) !== -1;
						}
						break;
					case "not_in":
						if (Array.isArray(conditionValue)) {
							shouldShow =
								conditionValue.indexOf(fieldValue) === -1 &&
								conditionValue.indexOf(fieldValueStr) === -1;
						} else {
							shouldShow =
								String(conditionValue)
									.split(",")
									.indexOf(fieldValueStr) === -1;
						}
						break;
					default:
						shouldShow = fieldValueStr === conditionValueStr;
				}

				// Show or hide field based on condition.
				// Use instant show/hide for initial load, animation for subsequent changes.
				if (shouldShow) {
					if (useAnimation !== false) {
						$field.slideDown(200);
					} else {
						$field.show();
					}
				} else {
					if (useAnimation !== false) {
						$field.slideUp(200);
					} else {
						$field.hide();
					}
				}
			};

			// Initial check without animation (instant).
			checkCondition(false);

			// Listen for changes on the condition field.
			var $conditionField = $("#" + conditionField);
			if ($conditionField.length > 0) {
				// Handle different field types.
				if (
					$conditionField.is(":checkbox") ||
					$conditionField.is(":radio")
				) {
					$conditionField.on("change", function () {
						checkCondition(true); // Use animation for user interactions.
					});
				} else {
					$conditionField.on("change keyup", function () {
						checkCondition(true); // Use animation for user interactions.
					});
				}
			}
		});
	}

	// Initialize display conditions immediately when DOM is ready.
	$(function () {
		handleDisplayConditions();
	});

	// Also run immediately if DOM is already loaded (for inline scripts).
	if (
		document.readyState === "complete" ||
		document.readyState === "interactive"
	) {
		setTimeout(handleDisplayConditions, 1);
	}

	// Re-initialize after AJAX updates (if needed).
	$(document).on("ur_settings_updated", function () {
		handleDisplayConditions();
	});

	$(document).on(
		"change",
		"#user_registration_stripe_test_mode",
		function () {
			if ($("#user_registration_stripe_test_mode").is(":checked")) {
				$("#user_registration_stripe_test_publishable_key")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_stripe_test_secret_key")
					.closest(".user-registration-global-settings")
					.show();

				$("#user_registration_stripe_live_publishable_key")
					.closest(".user-registration-global-settings")
					.hide();
				$("#user_registration_stripe_live_secret_key")
					.closest(".user-registration-global-settings")
					.hide();
			} else {
				$("#user_registration_stripe_test_publishable_key")
					.closest(".user-registration-global-settings")
					.hide();
				$("#user_registration_stripe_test_secret_key")
					.closest(".user-registration-global-settings")
					.hide();

				$("#user_registration_stripe_live_publishable_key")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_stripe_live_secret_key")
					.closest(".user-registration-global-settings")
					.show();
			}
		}
	);
	$(document).on(
		"change",
		"#user_registration_mollie_global_test_mode",
		function () {
			if (
				$("#user_registration_mollie_global_test_mode").is(":checked")
			) {
				$("#user_registration_mollie_global_test_publishable_key")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_mollie_global_live_publishable_key")
					.closest(".user-registration-global-settings")
					.hide();
			} else {
				$("#user_registration_mollie_global_live_publishable_key")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_mollie_global_test_publishable_key")
					.closest(".user-registration-global-settings")
					.hide();
			}
		}
	);
	$(document).on(
		"change",
		"#user_registration_authorize_net_test_mode",
		function () {
			if (
				$("#user_registration_authorize_net_test_mode").is(":checked")
			) {
				$("#user_registration_authorize_net_test_publishable_key")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_authorize_net_test_secret_key")
					.closest(".user-registration-global-settings")
					.show();

				$("#user_registration_authorize_net_live_publishable_key")
					.closest(".user-registration-global-settings")
					.hide();
				$("#user_registration_authorize_net_live_secret_key")
					.closest(".user-registration-global-settings")
					.hide();
			} else {
				$("#user_registration_authorize_net_test_publishable_key")
					.closest(".user-registration-global-settings")
					.hide();
				$("#user_registration_authorize_net_test_secret_key")
					.closest(".user-registration-global-settings")
					.hide();

				$("#user_registration_authorize_net_live_publishable_key")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_authorize_net_live_secret_key")
					.closest(".user-registration-global-settings")
					.show();
			}
		}
	);
	$(document).on(
		"change",
		"#user_registration_global_paypal_mode",
		function () {
			if ($(this).val() && "test" === $(this).val()) {
				$("#user_registration_global_paypal_test_email_address")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_global_paypal_test_client_id")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_global_paypal_test_client_secret")
					.closest(".user-registration-global-settings")
					.show();

				$("#user_registration_global_paypal_live_email_address")
					.closest(".user-registration-global-settings")
					.hide();
				$("#user_registration_global_paypal_live_client_id")
					.closest(".user-registration-global-settings")
					.hide();
				$("#user_registration_global_paypal_live_client_secret")
					.closest(".user-registration-global-settings")
					.hide();
			} else {
				$("#user_registration_global_paypal_live_email_address")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_global_paypal_live_client_id")
					.closest(".user-registration-global-settings")
					.show();
				$("#user_registration_global_paypal_live_client_secret")
					.closest(".user-registration-global-settings")
					.show();

				$("#user_registration_global_paypal_test_email_address")
					.closest(".user-registration-global-settings")
					.hide();
				$("#user_registration_global_paypal_test_client_id")
					.closest(".user-registration-global-settings")
					.hide();
				$("#user_registration_global_paypal_test_client_secret")
					.closest(".user-registration-global-settings")
					.hide();
			}
		}
	);

	// Function to trigger payment gateway mode changes
	function trigger_payment_gateway_mode_changes(gatewayToggleId) {
		// Mapping of gateway toggle IDs to their mode selectors
		var gatewayMap = {
			user_registration_paypal_enabled:
				"#user_registration_global_paypal_mode",
			user_registration_stripe_enabled:
				"#user_registration_stripe_test_mode",
			user_registration_authorize_net_enabled:
				"#user_registration_authorize_net_test_mode",
			user_registration_mollie_enabled:
				"#user_registration_mollie_global_test_mode"
		};
		if (gatewayToggleId && gatewayMap[gatewayToggleId]) {
			var $modeSelector = $(gatewayMap[gatewayToggleId]);

			if ($modeSelector.length > 0) {
				if (gatewayToggleId === "user_registration_paypal_enabled") {
					var value =
						$("#user_registration_global_paypal_mode").length > 0 &&
						$("#user_registration_global_paypal_mode").val()
							? $("#user_registration_global_paypal_mode").val()
							: "test";
					$modeSelector.val(value);
				}
				setTimeout(function () {
					$modeSelector.trigger("change");
				}, 0);
			}
		} else {
			if ($("#user_registration_authorize_net_test_mode").length > 0) {
				$("#user_registration_authorize_net_test_mode").trigger(
					"change"
				);
			}
			if ($("#user_registration_mollie_global_test_mode").length > 0) {
				$("#user_registration_mollie_global_test_mode").trigger(
					"change"
				);
			}
			if ($("#user_registration_stripe_test_mode").length > 0) {
				$("#user_registration_stripe_test_mode").trigger("change");
			}
			if ($("#user_registration_global_paypal_mode").length > 0) {
				$("#user_registration_global_paypal_mode").trigger("change");
			}
		}
	}

	trigger_payment_gateway_mode_changes();

	$(document).on("change", ".urm_toggle_pg_status", function () {
		var $toggle = $(this);
		var isChecked = $toggle.is(":checked");
		var $allSiblings = $toggle
			.closest(".user-registration-global-settings")
			.siblings(".user-registration-global-settings");
		var $siblingsExcludingLast = $allSiblings.not(":last");
		if (isChecked) {
			$siblingsExcludingLast.show();
			if ($toggle.attr("id") !== "user_registration_bank_enabled") {
				trigger_payment_gateway_mode_changes($toggle.attr("id"));
			}
		} else {
			$siblingsExcludingLast.hide();
		}
	});
	$(document).ready(function () {
		$(".urm_toggle_pg_status").trigger("change");
		$(
			"#user_registration_member_registration_page_id, #user_registration_thank_you_page_id"
		).trigger("change");
	});
})(jQuery);
