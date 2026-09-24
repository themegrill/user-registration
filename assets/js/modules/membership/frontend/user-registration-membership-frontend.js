/*global console, user_registration_params, Promise */
(function ($, urmf_data) {
	var elements = {};
	var stripe_mode_validated = false;
	var validated_stripe_pm_id = null;
	var stripe_card_state = { complete: false, error: null };
	var stripe_validation_in_progress = false;
	var ur_membership_frontend_utils = {
		/**
		 * Appends a spinner element to the specified element.
		 *
		 * @param {jQuery} $element - The jQuery element to which the spinner will be appended.
		 * @return {boolean} Returns true if the spinner was successfully appended, false otherwise.
		 */
		append_spinner: function ($element) {
			if ($element && $element.append) {
				var spinner = '<span class="urm-spinner is-active"></span>';
				$element.append(spinner);
				return true;
			}
			return false;
		},
		/**
		 * Remove spinner elements from a element.
		 *
		 * @param {jQuery} $element
		 */
		remove_spinner: function ($element) {
			if ($element && $element.remove) {
				$element.find(".urm-spinner").remove();
				return true;
			}
			return false;
		},

		/**
		 * Shows the payment processing spinner on the button container.
		 * Used during Stripe payment processing to indicate payment is in progress.
		 */
		show_payment_processing_overlay: function () {
			var $form = $(".ur-frontend-form").find("form.register");
			var $buttonContainer = $form.find(".ur-button-container");
			var $submitButton = $buttonContainer.find(".ur-submit-button");

			$submitButton.prop("disabled", true);
			if (!$submitButton.find("span.ur-front-spinner").length) {
				$submitButton.find("span").addClass("ur-front-spinner");
			}
		},

		/**
		 * Hides and removes the payment processing overlay.
		 */
		hide_payment_processing_overlay: function () {
			var $form = $(".ur-frontend-form").find("form.register");
			var $buttonContainer = $form.find(".ur-button-container");
			var $submitButton = $buttonContainer.find(".ur-submit-button");

			$submitButton.find("span").removeClass("ur-front-spinner");
			$submitButton.prop("disabled", false);
		},

		/**
		 * Updates the payment processing state (kept for API compatibility).
		 * Since we're using the button spinner, this just ensures spinner is shown.
		 */
		update_payment_processing_overlay: function () {
			this.show_payment_processing_overlay();
		},

		/**
		 * A function that converts an object to an array by taking its values, excluding the first two, and preserving the original order.
		 *
		 * @param {jQuery} $object - The jQuery object to be converted to an array.
		 * @return {Array} The array with values from the object, excluding the first two, and in the original order.
		 */
		convert_to_array: function ($object) {
			return Object.values($object).reverse().slice(2).reverse();
		},
		/**
		 *
		 * @param disable
		 * @param btn
		 */
		toggleSaveButtons: function (disable, btn) {
			disable = this.if_empty(disable, true);
			$(btn).prop("disabled", !!disable);
		},

		/**
		 *
		 * @param value
		 * @param _default
		 * @returns {*}
		 */
		if_empty: function (value, _default) {
			if (null === value || undefined === value || "" === value) {
				return _default;
			}
			return value;
		},

		clear_validation_error: function () {
			$("span.notice_red").each(function () {
				$(this).text("");
			});
		},

		show_validation_error: function (notice_div, message) {
			notice_div
				.removeClass("notice_blue")
				.addClass("notice_red")
				.text(message);
			this.clear_validation_error();
			var input = notice_div.siblings("input");
			$("html, body").animate(
				{
					scrollTop: notice_div.parent().offset().top
				},
				200
			);
			notice_div.text(message);
		},
		// Function to toggle the notice
		toggleNotice: function () {
			$(".notice-container").toggleClass("active");
			setTimeout(this.toggleNotice, 5000);
		},

		show_failure_message: function (message) {
			$(".notice-container .notice_blue")
				.removeClass("notice_blue")
				.addClass("notice_red");
			$(".notice_message").text(message);
			this.toggleNotice();
		},

		show_success_message: function (message) {
			var $registration_form = $(".ur-frontend-form").first(),
				$form = $registration_form.length
					? $registration_form.find("form.register")
					: $();

			if ($form.length) {
				$registration_form.find("#ur-submit-message-node").remove();
				$registration_form
					.find(".ur-submit-button")
					.find("span")
					.removeClass("ur-front-spinner");
				$registration_form
					.find(".ur-submit-button")
					.prop("disabled", false);

				var wrapper = $(
					'<div class="ur-message user-registration-message" id="ur-submit-message-node"/>'
				);
				wrapper.append(
					$('<ul class=""/>').append($("<li/>").text(message))
				);
				$form.append(wrapper);
				$(window).scrollTop(
					$registration_form.find(".ur-button-container").offset().top
				);
				$(".notice-container").removeClass("active");
			} else {
				$(".notice-container .notice_red")
					.removeClass("notice_red")
					.addClass("notice_blue");
				$(".notice_message").text(message);
				this.toggleNotice();
			}
		},
		show_form_success_message: function (form_response, thank_you_data) {
			if (
				form_response.data &&
				form_response.data.registration_type === "membership"
			) {
				thank_you_data = thank_you_data || {};
				thank_you_data.context = "hide_message";
			}
			var response_data = form_response.data,
				ursL10n = user_registration_params.ursL10n,
				$registration_form = $(
					"#user-registration-form-" + form_response.form_id
				),
				message = $('<ul class=""/>'),
				success_message_position =
					response_data.success_message_positon,
				redirect_url = $registration_form
					.find('input[name="ur-redirect-url"]')
					.val(),
				timeout = form_response.data.redirect_timeout
					? form_response.data.redirect_timeout
					: 2000;
			var originalRedirectUrl = redirect_url;

			if ("undefined" !== typeof response_data.role_based_redirect_url) {
				redirect_url = response_data.role_based_redirect_url;
			}
			if (
				typeof form_response.data.form_login_option !== "undefined" &&
				form_response.data.form_login_option === "sms_verification"
			) {
				window.setTimeout(function () {
					if (
						typeof form_response.data.redirect_url !==
							"undefined" &&
						form_response.data.redirect_url
					) {
						window.location = form_response.data.redirect_url;
					}
				}, timeout);
			}
			var has_thank_you_params =
				thank_you_data &&
				typeof thank_you_data === "object" &&
				Object.keys(thank_you_data).length > 0;
			if (
				"undefined" !== typeof redirect_url &&
				redirect_url !== "" &&
				!has_thank_you_params
			) {
				$(document).trigger(
					"user_registration_frontend_before_redirect_url",
					[redirect_url]
				);

				window.setTimeout(function () {
					window.location = redirect_url;
				}, timeout);

				if ("" != originalRedirectUrl) {
					return;
				}
			}
			/**
			 * Remove Spinner.
			 */
			$registration_form
				.find(".ur-submit-button")
				.find("span")
				.removeClass("ur-front-spinner");

			$registration_form.find("form")[0].reset();
			$registration_form.find(".user-registration-error").remove();

			/**
			 * Append Success Message according to login option.
			 */
			if (response_data.form_login_option == "admin_approval") {
				message.append("<li>" + ursL10n.user_under_approval + "</li>");
			} else if (
				response_data.form_login_option === "email_confirmation" ||
				response_data.form_login_option ===
					"admin_approval_after_email_confirmation"
			) {
				message.append("<li>" + ursL10n.user_email_pending + "</li>");
			} else {
				message.append(
					"<li>" + ursL10n.user_successfully_saved + "</li>"
				);
			}

			var searchParams = new URLSearchParams(window.location.search),
				action = searchParams.get("action");

			if (
				"hide_message" != thank_you_data.context &&
				(action === "register" || null === action)
			) {
				$registration_form.find("form")[0].reset();
				var wrapper = $(
					'<div class="ur-message user-registration-message" id="ur-submit-message-node"/>'
				);
				wrapper.append(message);

				// Check the position set by the admin and append message accordingly.
				if ("1" === success_message_position) {
					$registration_form.find("form").append(wrapper);
					$(window).scrollTop(
						$registration_form
							.find("form")
							.find(".ur-button-container")
							.offset().top
					);
				} else {
					$registration_form.find("form").prepend(wrapper);
					$(window).scrollTop(
						$registration_form
							.find("form")
							.closest(".ur-frontend-form")
							.offset().top
					);
				}
			}

			$registration_form
				.find("form")
				.find(".ur-submit-button")
				.prop("disabled", false);

			if ("undefined" !== typeof redirect_url && redirect_url !== "") {
				ur_membership_ajax_utils.show_default_response(
					redirect_url,
					thank_you_data,
					timeout
				);
			} else {
				if (
					typeof response_data.auto_login !== "undefined" &&
					response_data.auto_login
				) {
					ur_membership_ajax_utils.show_default_response(
						redirect_url,
						thank_you_data,
						timeout
					);
				}
			}
		},
		isEventRegistered: function (selector, eventType) {
			var events = $._data($(selector)[0], "events");
			return events && events[eventType];
		}
	};
	var ur_membership_ajax_utils = {
		/**
		 *
		 * @returns {{}}
		 */
		prepare_members_data: function () {
			var user_data = {},
				form_inputs = $("#ur-membership-registration").find(
					"input.ur_membership_input_class"
				),
				password = $(document).find("input#user_pass");

			form_inputs =
				ur_membership_frontend_utils.convert_to_array(form_inputs);
			form_inputs.forEach(function (item) {
				var $this = $(item);
				if ($this.attr("name") !== undefined) {
					var name = $this
						.attr("name")
						.toLowerCase()
						.replace("urm_", "");
					user_data[name] = $this.val();
				}
			});
			var membership_input = $('input[name="urm_membership"]:checked');
			user_data.membership = membership_input.val();
			user_data.payment_method = "free";
			user_data.password = password.val();
			// UR-4386: one-time plan zeroed by a 100% coupon (data-urm-free-order="1") stays a
			// $0 free order and keeps payment_method="free".
			if (
				membership_input.data("urm-pg-type") !== "free" &&
				membership_input.attr("data-urm-free-order") !== "1"
			) {
				user_data.payment_method = $(
					'input[name="urm_payment_method"]:checked'
				).val();
			}
			var date = new Date();
			user_data.start_date =
				date.getFullYear() +
				"-" +
				(date.getMonth() + 1) +
				"-" +
				date.getDate();

			// Append tax details if available
			var taxDetails = $(document).find("#ur-tax-details");

			if (taxDetails.length > 0) {
				user_data.tax_rate = taxDetails.data("tax-rate");
				user_data.tax_calculation_method = taxDetails.data(
					"tax-calculation-method"
				);
			}

			var localCurrency = membership_input.data("local-currency"),
				geoZoneId = membership_input.data("zone-id");
			if (localCurrency && geoZoneId) {
				user_data.switched_currency = localCurrency;
				user_data.urm_zone_id = geoZoneId;
			}

			if ($("#ur-local-currency-switch-currency").length) {
				user_data.switched_currency = $(
					"#ur-local-currency-switch-currency"
				).val();
				user_data.urm_zone_id = $(
					"#ur-local-currency-switch-currency"
				).data("urm-zone-id");
			}

			var container = membership_input.closest(".urm-team-pricing-card");
			var selected_team = container.find(
				".urm-team-pricing-tier.selected"
			);
			if (selected_team.length > 0) {
				user_data.team = selected_team.data("team");
				var seat_model = selected_team.data("seat-model");
				if ("variable" === seat_model) {
					user_data.no_of_seats = selected_team
						.find('input[name="no_of_seats"]')
						.val();
					var pricing_model = selected_team.data("pricing-model");
					if ("tier" === pricing_model) {
						var selected_tier = selected_team.data("selected-tier");
						user_data.tier = selected_tier;
					}
				}
			}

			return user_data;
		},
		prepare_coupons_apply_data: function () {
			var coupon_data = {};
			coupon_data.coupon = $("#ur-membership-coupon").val();
			coupon_data.membership_id = $(
				'input[name="urm_membership"]:checked'
			).val();
			var membership_input = $('input[name="urm_membership"]:checked');

			var taxDetails = $(document).find("#ur-tax-details");

			if (taxDetails.length > 0) {
				coupon_data.tax_rate = taxDetails.data("tax-rate");
				coupon_data.tax_calculation_method = taxDetails.data(
					"tax-calculation-method"
				);
			}

			var localCurrency = membership_input.data("local-currency"),
				geoZoneId = membership_input.data("zone-id");
			if (localCurrency && geoZoneId) {
				coupon_data.switched_currency = localCurrency;
				coupon_data.urm_zone_id = geoZoneId;
			}

			if ($("#ur-local-currency-switch-currency").length) {
				coupon_data.switched_currency = $(
					"#ur-local-currency-switch-currency"
				).val();
				coupon_data.urm_zone_id = $(
					"#ur-local-currency-switch-currency"
				).data("urm-zone-id");
			}

			return coupon_data;
		},
		/**
		 * validate membership form before submit
		 * @returns {boolean}
		 */
		validate_membership_form: function (is_upgrade) {
			if (typeof is_upgrade === "undefined") {
				is_upgrade = false;
			}
			var no_errors = true,
				pg_inputs = $('input[name="urm_payment_method"]:checked'),
				selected_tier = $(".urm-team-pricing-tier.selected"),
				seat_input = selected_tier.find(".ur-team-tier-seats-input");

			if (pg_inputs.length > 0) {
				pg_inputs.each(function () {
					if ($(this).val() === "stripe" && $(this).is(":checked")) {
						var is_empty = is_upgrade
							? $(".membership-upgrade-container").find(
									".stripe-input-container .StripeElement--empty"
								).length
							: $(".ur-frontend-form").find(
									".stripe-input-container .StripeElement--empty"
								).length;

						if (is_empty) {
							no_errors = false;
							var event = {
								error: {
									message:
										urmf_data.labels.i18n_empty_card_details
								}
							};

							elements.card.emit("change", event);
						} else if (!stripe_card_state.complete) {
							// Card element has input but Stripe.js reports it
							// incomplete/invalid (e.g. missing postal code).
							// Block the submit here, before the registration
							// request creates the user, instead of letting
							// confirmCardPayment fail afterwards.
							no_errors = false;
							var incomplete_event = {
								error: {
									message:
										stripe_card_state.error &&
										stripe_card_state.error.message
											? stripe_card_state.error.message
											: urmf_data.labels
													.i18n_empty_card_details
								}
							};

							elements.card.emit("change", incomplete_event);
						}
					}
				});
			}
			// seats validation if less than min seats, set the seat input to min seats and if greater than max seats, set the seat input to max seats and calculate total.
			if (seat_input.length > 0) {
				var no_of_seats = parseInt(seat_input.val(), 10);
				var pricing_model = selected_tier.data("pricing-model");
				var min_seats = parseInt(
					selected_tier.data("minimum-seats"),
					10
				);
				var max_seats = parseInt(
					selected_tier.data("maximum-seats"),
					10
				);
				var amount = 0;

				if (isNaN(no_of_seats) || no_of_seats < min_seats) {
					seat_input.val(min_seats);
					no_of_seats = min_seats;
				}
				if (no_of_seats > max_seats) {
					seat_input.val(max_seats);
					no_of_seats = max_seats;
				}

				if ("per_seat" === pricing_model) {
					amount = selected_tier.data("per-seat-price");
				} else {
					var tiers = selected_tier.data("price-tiers");
					$.each(tiers, function (index, tierItem) {
						var from = parseInt(tierItem.tier_from, 10);
						var to = parseInt(tierItem.tier_to, 10);

						if (no_of_seats >= from && no_of_seats <= to) {
							amount = tierItem.tier_per_seat_price;
							return false;
						}
					});
				}
				if (no_of_seats >= min_seats && no_of_seats <= max_seats) {
					var container = selected_tier.closest(
						".urm-team-pricing-card"
					);
					var radio = container.find('input[type="radio"]');
					var total = no_of_seats * amount;
					radio.data("urm-pg-calculated-amount", total);
					ur_membership_ajax_utils.calculate_total(radio);
				}
			}
			if (no_errors) {
				ur_membership_frontend_utils.clear_validation_error();
			}

			return no_errors;
		},
		validate_coupon_data: function () {
			var coupon = $("#ur-membership-coupon").val(),
				membership = $('input[name="urm_membership"]:checked'),
				error_div = $("#coupon-validation-error"),
				no_error = true;
			ur_membership_frontend_utils.clear_validation_error();
			//coupon can not be empty
			if (coupon.length < 1) {
				no_error = false;
				ur_membership_frontend_utils.show_validation_error(
					error_div,
					urmf_data.labels.i18n_error +
						"! " +
						urmf_data.labels.i18n_coupon_empty_error
				);
				return no_error;
			}
			//membership must be selected
			if (membership.length === 0) {
				no_error = false;
				ur_membership_frontend_utils.show_validation_error(
					error_div,
					urmf_data.labels.i18n_error +
						"! " +
						urmf_data.labels.i18n_membership_required
				);
				return no_error;
			}
			//membership cannot be free
			if (membership.data("urm-pg-type") === "free") {
				no_error = false;
				ur_membership_frontend_utils.show_validation_error(
					error_div,
					urmf_data.labels.i18n_error +
						"! " +
						urmf_data.labels.i18n_coupon_free_membership_error
				);
				return no_error;
			}
			return no_error;
		},
		/**
		 * called to create a new membership
		 * @param data
		 */
		create_member: function (form_response) {
			var prepare_members_data = this.prepare_members_data();
			prepare_members_data.username = form_response.data.username;
			var self = this;

			function do_send() {
				self.send_data(
					{
						action: "user_registration_membership_register_member",
						members_data: JSON.stringify(prepare_members_data),
						form_response: JSON.stringify(form_response.data)
					},
					{
						success: function (response) {
							if (response.success) {
								ur_membership_ajax_utils.handle_response(
									response,
									prepare_members_data,
									form_response
								);
							} else {
								ur_membership_frontend_utils.show_failure_message(
									response.data.message
								);
								form_object.hide_loader(form_response.form_id);
							}
						},
						failure: function (xhr, statusText) {
							ur_membership_frontend_utils.show_failure_message(
								urmf_data.labels.network_error +
									"(" +
									statusText +
									")"
							);
							form_object.hide_loader(form_response.form_id);
						},
						complete: function () {
							// form_object.hide_loader(form_response.form_id);
						}
					}
				);
			}

			if (
				prepare_members_data.payment_method === "stripe" &&
				elements &&
				elements.stripe &&
				elements.card
			) {
				elements.stripe
					.createPaymentMethod({ type: "card", card: elements.card })
					.then(function (result) {
						if (result.error) {
							prepare_members_data.stripe_pm_error =
								result.error.message;
						} else {
							prepare_members_data.payment_method_id =
								result.paymentMethod.id;
						}
						do_send();
					});
			} else {
				do_send();
			}
		},
		/**
		 * Handles the response based on the payment method selected.
		 *
		 * @param {Object} response - The response data from the server.
		 * @param {Object} prepare_members_data - The data for preparing members.
		 */
		handle_response: function (
			response,
			prepare_members_data,
			form_response
		) {
			switch (prepare_members_data.payment_method) {
				case "paypal": //for paypal response must contain `payment_url` field
					ur_membership_frontend_utils.show_success_message(
						response.data.message
					);
					if (
						response.data.pg_data &&
						response.data.pg_data.payment_url
					) {
						window.location.replace(
							response.data.pg_data.payment_url
						);
					} else {
						ur_membership_frontend_utils.show_failure_message(
							(response.data.pg_data &&
								response.data.pg_data.payment_error) ||
								"PayPal redirect failed. Please check your PayPal configuration."
						);
					}
					break;
				case "bank":
					ur_membership_frontend_utils.show_success_message(
						response.data.message
					);
					this.show_bank_response(
						response,
						prepare_members_data,
						form_response
					);
					break;
				case "stripe":
					stripe_settings.handle_stripe_response(
						response,
						prepare_members_data,
						form_response
					);
					break;
				case "authorize":
					ur_membership_frontend_utils.show_success_message(
						response.data.message
					);
					window.location.replace(response.data.redirect);
					break;
				case "mollie":
					ur_membership_frontend_utils.show_success_message(
						response.data.message
					);
					window.location.replace(response.data.pg_data.payment_url);
					break;
				default:
					ur_membership_frontend_utils.show_success_message(
						response.data.message
					);
					ur_membership_frontend_utils.show_form_success_message(
						form_response,
						{
							transaction_id: response.data.transaction_id || "",
							payment_type: "unpaid",
							info: "Free",
							username: prepare_members_data.username,
							context: "hide_message"
						}
					);
					break;
			}
		},

		/**
		 * Handles the response for showing bank information.
		 *
		 * @param {Object} response - The response data from the server.
		 * @return {void} No return value.
		 */
		show_bank_response: function (
			response,
			prepare_members_data,
			form_response
		) {
			var bank_data = {
				transaction_id: response.data.transaction_id,
				payment_type: "unpaid",
				info: response.data.pg_data.data,
				username: prepare_members_data.username,
				context: "hide_message",
				message: response.data.message || ""
			};

			var shouldRedirect =
				!!response.data.is_renewing ||
				!!response.data.is_upgrading ||
				!!response.data.is_purchasing_multiple;

			var redirectUrl =
				$("#urm-redirect-url").val() ||
				response.data.redirect_url ||
				window.location.href;

			if (shouldRedirect) {
				ur_membership_ajax_utils.show_default_response(
					redirectUrl,
					bank_data
				);
			} else {
				ur_membership_frontend_utils.show_form_success_message(
					form_response,
					bank_data
				);
			}
		},

		/**
		 * Shows the default response when payment method is free.
		 */
		show_default_response: function (url, thank_you_data, timeout) {
			timeout = timeout || 2000;
			if (!url || String(url).trim() === "") {
				return;
			}

			var url_params = $.param(thank_you_data).toString();
			window.setTimeout(function () {
				var hasQuery = url.indexOf("?") !== -1;
				var concatenator = hasQuery ? "&" : "?";
				window.location.replace(url + concatenator + url_params);
			}, timeout);
		},
		validate_coupon: function ($this) {
			ur_membership_frontend_utils.toggleSaveButtons(true, $this);
			ur_membership_frontend_utils.append_spinner($this);

			if (this.validate_coupon_data()) {
				var data = {
						action: "user_registration_membership_validate_coupon",
						coupon_data: this.prepare_coupons_apply_data()
					},
					membership_field = $this
						.closest(".ur_membership_registration_container")
						.find("input[name='urm_membership']:checked"),
					upgrade_type = membership_field.data("urm-upgrade-type");

				if (upgrade_type) {
					data.coupon_data.upgrade_amount = membership_field.data(
						"urm-pg-calculated-amount"
					);
				}

				this.send_data(data, {
					success: function (response) {
						if (response.success) {
							ur_membership_ajax_utils.handle_coupon_validation_response(
								response
							);
						} else {
							ur_membership_frontend_utils.show_failure_message(
								response.data.message
							);
						}
					},
					failure: function (xhr, statusText) {
						if (xhr.status === 500) {
							ur_membership_frontend_utils.show_failure_message(
								urmf_data.labels.network_error +
									"(" +
									statusText +
									")"
							);
						} else {
							ur_membership_frontend_utils.show_validation_error(
								$("#coupon-validation-error"),
								urmf_data.labels.i18n_error +
									"! " +
									xhr.responseJSON.data.message
							);
						}
					},
					complete: function () {
						ur_membership_frontend_utils.remove_spinner($this);
						ur_membership_frontend_utils.toggleSaveButtons(
							false,
							$this
						);
					}
				});
			} else {
				ur_membership_frontend_utils.toggleSaveButtons(false, $this);
				ur_membership_frontend_utils.remove_spinner($this);
			}
		},
		handle_coupon_validation_response: function (response) {
			$(".urm_apply_coupon").hide();
			//show success message
			ur_membership_frontend_utils.clear_validation_error();
			$("#coupon-validation-error")
				.removeClass("notice_red")
				.addClass("notice_blue")
				.text(response.data.message);
			//handle discount notice part
			response = JSON.parse(response.data.data);
			var selected_membership = $('input[name="urm_membership"]:checked'),
				prefix = "";
			//add discount amount as attribute on selected membership

			selected_membership.attr(
				"data-ur-discount-amount",
				response.discount_amount
			);
			//calculate total
			ur_membership_ajax_utils.calculate_total(selected_membership);

			prefix =
				response.coupon_details.coupon_discount_type === "fixed"
					? "" + response.coupon_details.coupon_discount
					: response.coupon_details.coupon_discount + "%";
			// show notice below total
			$("#total-input-notice").text(
				prefix + " " + urmf_data.labels.i18n_coupon_discount_message
			);
		},
		/**
		 * Clear an applied coupon and recalculate totals.
		 * Used by the cancel (X) control and when the coupon input is emptied.
		 *
		 * @param {Object} options Optional settings.
		 * @param {boolean} options.skipEmptyInput Skip clearing the input when already empty.
		 */
		clear_applied_coupon: function (options) {
			options = options || {};

			if (!options.skipEmptyInput) {
				$("#ur-membership-coupon").val("");
			}

			$("#coupon-validation-error")
				.text("")
				.removeClass("notice_blue notice_red");
			$(".urm_apply_coupon").show();
			$("#total-input-notice").text("");

			var selected_membership = $(
				'input[name="urm_membership"]:checked'
			);

			selected_membership
				.removeData("ur-discount-amount")
				.removeAttr("data-ur-discount-amount");

			if (selected_membership.length) {
				ur_membership_ajax_utils.calculate_total(selected_membership);
			}
		},
		/**
		 * Send data to the backend API.
		 *
		 * @param {JSON} data Data to send.
		 * @param {JSON} callbacks Callbacks list.
		 */
		send_data: function (data, callbacks) {
			var success_callback =
					"function" === typeof callbacks.success
						? callbacks.success
						: function () {},
				failure_callback =
					"function" === typeof callbacks.failure
						? callbacks.failure
						: function () {},
				beforeSend_callback =
					"function" === typeof callbacks.beforeSend
						? callbacks.beforeSend
						: function () {},
				complete_callback =
					"function" === typeof callbacks.complete
						? callbacks.complete
						: function () {};

			// Inject default data.
			if (!data._wpnonce && urmf_data) {
				data._wpnonce = urmf_data._nonce;
			}
			$.ajax({
				type: "post",
				dataType: "json",
				url: urmf_data.ajax_url,
				data: data,
				beforeSend: beforeSend_callback,
				success: success_callback,
				error: failure_callback,
				complete: complete_callback
			});
		},

		calculate_total: function ($this) {
			var urm_calculated_total = $this.data("urm-pg-calculated-amount");
			var subTotalInput = $("#ur-membership-subtotal");
			var taxInput = $("#ur-membership-tax");
			var couponInput = $("#ur-membership-coupons");
			var proratedDiscountInput = $("#ur-membership-proration-discount");
			var localCurrency = "";
			var currency = urmf_data.currency_symbol;

			// .data() returns "299.00" as a string, and convert_currency_and_calculate_tax() calls .toFixed() on it.
			subTotal = parseFloat(urm_calculated_total);

			if ($this.data("local-currency")) {
				localCurrency = $this.data("local-currency");
				currency = register_events.decodeHtmlEntity(
					urmf_data.local_currencies_symbol[localCurrency] || currency
				);
			}

			var total_input = $("#ur-membership-total"),
				discount_amount = $this.data("ur-discount-amount"),
				total =
					discount_amount !== undefined && discount_amount !== ""
						? urm_calculated_total - discount_amount
						: urm_calculated_total,
				upgrade_type = $this.data("urm-upgrade-type");

			var membershipAmount =
				parseFloat($this.data("urm-membership-amount")) || 0;
			var proratedDiscount = 0;
			if (upgrade_type) {
				proratedDiscount =
					membershipAmount - parseFloat(urm_calculated_total);
				$(".urm-membership-proration-discount-value").removeClass(
					"urm-d-none"
				);
			} else {
				$(".urm-membership-proration-discount-value").addClass(
					"urm-d-none"
				);
			}

			$(".urm-membership-sub-total-value")
				.find(".user-registration-badge")
				.remove();
			$(".urm-membership-total-value")
				.find(".user-registration-badge")
				.remove();

			var totalDetails =
				ur_membership_ajax_utils.convert_currency_and_calculate_tax(
					$this,
					total,
					subTotal,
					discount_amount
				);

			total = parseFloat(total).toFixed(2);
			if ("left" === urmf_data.curreny_pos) {
				total_input.text(
					currency + parseFloat(totalDetails.total).toFixed(2)
				);
				subTotalInput.text(
					currency + parseFloat(totalDetails.subTotal).toFixed(2)
				);
				if (parseFloat(totalDetails.taxAmount) > 0) {
					taxInput.closest(".urm-membership-tax-value").show();
					taxInput.text(
						"+" +
							currency +
							parseFloat(totalDetails.taxAmount).toFixed(2)
					);
				} else {
					taxInput.closest(".urm-membership-tax-value").hide();
				}
				if (totalDetails.discountAmount > 0) {
					couponInput.closest(".urm-membership-coupons-value").show();

					couponInput.text(
						"-" +
							currency +
							parseFloat(totalDetails.discountAmount).toFixed(2)
					);
				} else {
					couponInput.closest(".urm-membership-coupons-value").hide();
				}
				proratedDiscountInput.text(
					"-" + currency + parseFloat(proratedDiscount).toFixed(2)
				);
			} else {
				total_input.text(
					parseFloat(totalDetails.total).toFixed(2) + currency
				);
				subTotalInput.text(
					parseFloat(totalDetails.subTotal).toFixed(2) + currency
				);
				if (parseFloat(totalDetails.taxAmount) > 0) {
					taxInput.closest(".urm-membership-tax-value").show();
					taxInput.text(
						"+" +
							parseFloat(totalDetails.taxAmount).toFixed(2) +
							currency
					);
				} else {
					taxInput.closest(".urm-membership-tax-value").hide();
				}

				if (totalDetails.discountAmount > 0) {
					couponInput.closest(".urm-membership-coupons-value").show();

					couponInput.text(
						"-" +
							parseFloat(totalDetails.discountAmount).toFixed(2) +
							currency
					);
				} else {
					couponInput.closest(".urm-membership-coupons-value").hide();
				}
				proratedDiscountInput.text(
					"-" + parseFloat(proratedDiscount).toFixed(2) + currency
				);

				if (totalDetails.discountAmount > 0) {
					couponInput.closest(".urm-membership-coupons-value").show();

					couponInput.text(
						"-" +
							parseFloat(totalDetails.discountAmount).toFixed(2) +
							currency
					);
				} else {
					couponInput.closest(".urm-membership-coupons-value").hide();
				}
				proratedDiscountInput.text(
					"-" + parseFloat(proratedDiscount).toFixed(2) + currency
				);
			}

			var hasDiscount =
				totalDetails.discountAmount > 0 || proratedDiscount > 0;
			var hasAdjustment =
				hasDiscount || parseFloat(totalDetails.taxAmount) > 0;

			$(".urm-pre-subtotal-divider").toggle(hasDiscount);
			$(".urm-membership-sub-total-value").toggle(hasAdjustment);
			$(".urm-pre-total-divider").toggle(hasAdjustment);

			// UR-4386: one-time (paid) plan zeroed by a 100% coupon => hide the gateway and submit
			// as a free order. Subscriptions are untouched (they keep the gateway for renewals).
			if ("paid" === $this.data("urm-pg-type")) {
				if (parseFloat(totalDetails.total) <= 0) {
					$(".ur_payment_gateway_container").addClass("urm-d-none");
					$(".stripe-container").addClass("urm-d-none");
					$this.attr("data-urm-free-order", "1");
				} else if ($this.attr("data-urm-free-order") === "1") {
					// Discount no longer covers the full amount — restore the gateway selector.
					$(".ur_payment_gateway_container").removeClass("urm-d-none");
					$(".stripe-container").removeClass("urm-d-none");
					$this.removeAttr("data-urm-free-order");
				}
			}
		},
		upgrade_membership: function (
			data,
			current_plan,
			selected_membership_id,
			current_subscription_id,
			selected_pg,
			btn
		) {
			//handle differently in case of Authorize.NET
			//gets the nonce token from ANET and send it via the AJAX request.
			if ("authorize" === selected_pg) {
				this.handle_authorize_upgrade(
					data,
					current_plan,
					selected_membership_id,
					current_subscription_id,
					selected_pg,
					btn
				);
			} else {
				var taxDetailsEl = $(document).find("#ur-tax-details");
				var $upgradeCurrencyEl = $(
					"#ur-local-currency-switch-currency"
				);
				this.send_data(
					{
						_wpnonce: urmf_data.upgrade_membership_nonce,
						action: "user_registration_membership_upgrade_membership",
						form_data: data.form_data,
						form_id: data.form_id,
						current_membership_id: current_plan,
						selected_membership_id: selected_membership_id,
						current_subscription_id: current_subscription_id,
						selected_pg: selected_pg,
						coupon: data.coupon,
						switched_currency: $upgradeCurrencyEl.length
							? $upgradeCurrencyEl.val()
							: "",
						urm_zone_id: $upgradeCurrencyEl.length
							? $upgradeCurrencyEl.data("urm-zone-id")
							: "",
						tax_rate: taxDetailsEl.length
							? taxDetailsEl.data("tax-rate")
							: "",
						tax_calculation_method: taxDetailsEl.length
							? taxDetailsEl.data("tax-calculation-method")
							: ""
					},
					{
						success: function (response) {
							if (response.success) {
								ur_membership_frontend_utils.show_success_message(
									response.data.message
								);
								var prepare_members_data = {
									payment_method: selected_pg,
									username: response.data.username
								};

								ur_membership_ajax_utils.handle_update_response(
									response,
									prepare_members_data
								);
							} else {
								ur_membership_frontend_utils.show_failure_message(
									response.data.message
								);
							}
						},
						failure: function (xhr, statusText) {
							ur_membership_frontend_utils.show_failure_message(
								user_registration_params.network_error +
									"(" +
									statusText +
									")"
							);
						},
						complete: function () {
							if (selected_pg !== "stripe") {
								ur_membership_frontend_utils.remove_spinner(
									btn
								);
							}
							ur_membership_frontend_utils.toggleSaveButtons(
								false,
								btn
							);
						}
					}
				);
			}
		},
		convert_currency_and_calculate_tax: function (
			$this,
			total,
			subTotal,
			discount_amount
		) {
			var $membershipRadio = $this,
				localCurrencyDetails =
					$membershipRadio.data("urm-local-currency-details") || {},
				membershipType = $membershipRadio.data("urm-pg-type"),
				totalDetails = {},
				taxAmount = 0,
				$localCurrencyEl = $("#ur-local-currency-switch-currency"),
				currency = $localCurrencyEl.val(),
				currencySymbols =
					ur_membership_frontend_localized_data.local_currencies_symbol,
				symbol = register_events.decodeHtmlEntity(
					currencySymbols[currency] || ""
				),
				discount_amount =
					typeof discount_amount !== "undefined"
						? parseFloat(discount_amount)
						: 0,
				membershipAmount = $membershipRadio.data(
					"urm-membership-amount"
				),
				upgradeType = $membershipRadio.data("urm-upgrade-type") || null;

			totalDetails.total = total;
			totalDetails.taxAmount = 0;
			totalDetails.subTotal = subTotal;
			totalDetails.discountAmount = discount_amount;

			if (membershipType !== "free") {
				if (
					localCurrencyDetails[currency] &&
					localCurrencyDetails[currency].hasOwnProperty("ID")
				) {
					$localCurrencyEl
						.data("urm-zone-id", localCurrencyDetails[currency].ID)
						.attr(
							"data-urm-zone-id",
							localCurrencyDetails[currency].ID
						);
				}
				var $span = $membershipRadio.siblings(
					".ur-membership-period-span"
				);
				var $periodText = $span.find(".ur-membership-period-text");

				// Parse duration assuming text
				var oldText = $periodText.length
					? $periodText.text()
					: $span.text();
				var durationPart = "";
				var everyIndex = oldText.toLowerCase().indexOf(" every ");

				if (everyIndex !== -1) {
					durationPart = oldText.substring(everyIndex).trim();
				} else {
					durationPart = oldText.replace(/^[^\s]+\s*/, "").trim();
				}

				if (localCurrencyDetails[currency]) {
					var newCalculatedValue =
						total * parseFloat(localCurrencyDetails[currency].rate);
					var newSubTotal =
						subTotal *
						parseFloat(localCurrencyDetails[currency].rate);
					var newDiscount =
						discount_amount *
						parseFloat(localCurrencyDetails[currency].rate);

					if (
						"manual" ==
						localCurrencyDetails[currency].pricing_method
					) {
						// "rate" is a flat local price here, not a multiplier — scale by the discount ratio.
						var fullManualPrice = parseFloat(
							localCurrencyDetails[currency].rate
						);
						var discountRatio =
							subTotal > 0 ? total / subTotal : 1;

						newCalculatedValue = fullManualPrice * discountRatio;
						newSubTotal = fullManualPrice;
						newDiscount = fullManualPrice - newCalculatedValue;
					}

					if (urmf_data.curreny_pos === "left") {
						$periodText.length
							? $periodText.text(
									symbol +
										newSubTotal.toFixed(2) +
										" " +
										durationPart
								)
							: $span.text(
									symbol +
										newSubTotal.toFixed(2) +
										" " +
										durationPart
								);
					} else {
						$periodText.length
							? $periodText.text(
									newSubTotal.toFixed(2) +
										symbol +
										" " +
										durationPart
								)
							: $span.text(
									newSubTotal.toFixed(2) +
										symbol +
										" " +
										durationPart
								);
					}

					$membershipRadio.data(
						"urm-converted-amount",
						newCalculatedValue
					);
					$membershipRadio
						.data("local-currency", currency)
						.attr("data-local-currency", currency);
					if ($membershipRadio.is(":checked")) {
						taxAmount =
							ur_membership_ajax_utils.calculate_tax_amount(
								newCalculatedValue
							);
						totalDetails.total =
							parseFloat(newCalculatedValue) +
							parseFloat(taxAmount);

						totalDetails.taxAmount = taxAmount;
						totalDetails.subTotal = newSubTotal;
						totalDetails.discountAmount = newDiscount;
					}
				} else {
					$membershipRadio.data("urm-converted-amount", 0);
					if (urmf_data.curreny_pos === "left") {
						if (upgradeType) {
							$periodText.length
								? $periodText.text(
										urmf_data.currency_symbol +
											membershipAmount.toFixed(2) +
											" " +
											durationPart
									)
								: $span.text(
										urmf_data.currency_symbol +
											membershipAmount.toFixed(2) +
											" " +
											durationPart
									);
						} else if (subTotal) {
							$periodText.length
								? $periodText.text(
										urmf_data.currency_symbol +
											subTotal.toFixed(2) +
											" " +
											durationPart
									)
								: $span.text(
										urmf_data.currency_symbol +
											subTotal.toFixed(2) +
											" " +
											durationPart
									);
						}
						if ($membershipRadio.is(":checked")) {
							taxAmount =
								ur_membership_ajax_utils.calculate_tax_amount(
									total
								);

							totalDetails.total =
								parseFloat(total) + parseFloat(taxAmount);

							totalDetails.taxAmount = taxAmount;
							totalDetails.subTotal = total;
						}
					} else {
						if (upgradeType) {
							$periodText.length
								? $periodText.text(
										membershipAmount.toFixed(2) +
											urmf_data.currency_symbol +
											" " +
											durationPart
									)
								: $span.text(
										membershipAmount.toFixed(2) +
											urmf_data.currency_symbol +
											" " +
											durationPart
									);
						} else if (subTotal) {
							$periodText.length
								? $periodText.text(
										subTotal.toFixed(2) +
											urmf_data.currency_symbol +
											" " +
											durationPart
									)
								: $span.text(
										subTotal.toFixed(2) +
											urmf_data.currency_symbol +
											" " +
											durationPart
									);
						}
						if ($membershipRadio.is(":checked")) {
							taxAmount =
								ur_membership_ajax_utils.calculate_tax_amount(
									total
								);
							totalDetails.total =
								parseFloat(total) + parseFloat(taxAmount);

							totalDetails.taxAmount = taxAmount;
							totalDetails.subTotal = total;
						}
					}
				}
			}

			return totalDetails;
		},
		calculate_tax_amount: function (total) {
			var countryField = $(document).find(".ur-field-address-country");
			var stateField = $(document).find(".ur-field-address-state");
			var taxRate = 0;
			var taxAmount = 0;

			if (countryField.length) {
				var country = countryField.val() || "";
				var state = stateField.val() || "";

				var regions = null;

				if (
					typeof urmf_data !== "undefined" &&
					urmf_data.regions_list &&
					urmf_data.regions_list.regions &&
					country &&
					urmf_data.regions_list.regions.hasOwnProperty(country)
				) {
					regions = urmf_data.regions_list.regions[country];
				}

				var tax_calculation_method = urmf_data.tax_calculation_method;
				var total_input = $("#ur-membership-total");

				if (urmf_data.is_tax_calculation_enabled) {
					if (regions) {
						if (regions.hasOwnProperty("states") && "" !== state) {
							if (regions.states.hasOwnProperty(state)) {
								taxRate = regions.states[state];
							} else {
								taxRate = regions.rate;
							}
						} else {
							taxRate = regions.rate;
						}

						if (taxRate > 0) {
							if (tax_calculation_method) {
								taxAmount = (total * taxRate) / 100;
								total =
									parseFloat(total) + parseFloat(taxAmount);
							} else {
								// Price include tax
							}
						}
					}

					$("#ur-tax-details").remove();

					var taxDetailsInput =
						'<input type="hidden" ' +
						'id="ur-tax-details" ' +
						'name="ur_tax_details" ' +
						'data-tax-rate="' +
						taxRate +
						'" ' +
						'data-tax-calculation-method="' +
						tax_calculation_method +
						'" ' +
						'data-total="' +
						total +
						'">';

					total_input.after(taxDetailsInput);
				}
			}
			$(".urm-membership-tax-value")
				.find(".ur_membership_input_label")
				.text(taxRate + "% Tax");

			return taxAmount;
		},
		add_multiple_membership: function (
			data,
			selected_membership_id,
			selected_pg,
			btn,
			type
		) {
			//handle differently in case of Authorize.NET
			//gets the nonce token from ANET and send it via the AJAX request.
			if ("authorize" === selected_pg) {
				this.handle_authorize_multiple_purchase(
					data,
					selected_membership_id,
					selected_pg,
					btn,
					type
				);
			} else {
				var taxDetailsElMultiple = $(document).find(
					"#ur-tax-details"
				);
				var $multipleCurrencyEl = $(
					"#ur-local-currency-switch-currency"
				);
				this.send_data(
					{
						_wpnonce: urmf_data.upgrade_membership_nonce,
						action: "user_registration_membership_add_multiple_membership",
						selected_membership_id: selected_membership_id,
						selected_pg: selected_pg,
						form_data: data.form_data,
						coupon: data.coupon,
						form_id: data.form_id,
						type: type,
						switched_currency: $multipleCurrencyEl.length
							? $multipleCurrencyEl.val()
							: "",
						urm_zone_id: $multipleCurrencyEl.length
							? $multipleCurrencyEl.data("urm-zone-id")
							: "",
						tax_rate: taxDetailsElMultiple.length
							? taxDetailsElMultiple.data("tax-rate")
							: "",
						tax_calculation_method: taxDetailsElMultiple.length
							? taxDetailsElMultiple.data(
									"tax-calculation-method"
								)
							: ""
					},
					{
						success: function (response) {
							if (response.success) {
								ur_membership_frontend_utils.show_success_message(
									response.data.message
								);
								var prepare_members_data = {
									payment_method: selected_pg,
									username: response.data.username
								};

								ur_membership_ajax_utils.handle_update_response(
									response,
									prepare_members_data
								);
							} else {
								ur_membership_frontend_utils.show_failure_message(
									response.data.message
								);
							}
						},
						failure: function (xhr, statusText) {
							ur_membership_frontend_utils.show_failure_message(
								user_registration_params.network_error +
									"(" +
									statusText +
									")"
							);
						},
						complete: function () {
							if (selected_pg !== "stripe") {
								ur_membership_frontend_utils.remove_spinner(
									btn
								);
							}
							ur_membership_frontend_utils.toggleSaveButtons(
								false,
								btn
							);
						}
					}
				);
			}
		},
		renew_membership: function (
			data,
			selected_pg,
			btn,
			membership_id,
			team_id
		) {
			this.send_data(
				{
					_wpnonce: urmf_data.renew_membership_nonce,
					action: "user_registration_membership_renew_membership",
					form_data: data.form_data,
					selected_pg: selected_pg,
					membership_id: membership_id,
					form_id: data.form_id,
					coupon: data.coupon
				},
				{
					success: function (response) {
						if (response.success) {
							ur_membership_frontend_utils.show_success_message(
								response.data.message
							);
							var prepare_members_data = {
								payment_method: selected_pg,
								username: response.data.username
							};

							ur_membership_ajax_utils.handle_renewal_response(
								response,
								prepare_members_data
							);
						} else {
							ur_membership_frontend_utils.show_failure_message(
								response.data.message
							);
						}
					},
					failure: function (xhr, statusText) {
						ur_membership_frontend_utils.show_failure_message(
							user_registration_params.network_error +
								"(" +
								statusText +
								")"
						);
					},
					complete: function () {
						ur_membership_frontend_utils.toggleSaveButtons(
							false,
							btn
						);
					}
				}
			);
		},
		handle_authorize_upgrade: function (
			submittedData,
			current_plan,
			selected_membership_id,
			current_subscription_id,
			selected_pg,
			btn
		) {
			var data = {
				current_plan: current_plan,
				selected_membership_id: selected_membership_id,
				current_subscription_id: current_subscription_id,
				selected_pg: selected_pg,
				btn: btn,
				form_data: submittedData.form_data,
				coupon: submittedData.coupon
			};

			var taxDetailsElAuth = $(document).find("#ur-tax-details");
			var $upgradeAuthCurrencyEl = $(
				"#ur-local-currency-switch-currency"
			);
			$(document).trigger("urm_before_upgrade_membership_submit", {
				data: data,
				onComplete: function (data) {
					ur_membership_ajax_utils.send_data(
						{
							_wpnonce: urmf_data.upgrade_membership_nonce,
							action: "user_registration_membership_upgrade_membership",
							current_membership_id: data.current_plan,
							selected_membership_id: data.selected_membership_id,
							current_subscription_id:
								data.current_subscription_id,
							selected_pg: data.selected_pg,
							ur_authorize_data: data.ur_authorize_data,
							form_data: submittedData.form_data,
							coupon: submittedData.coupon,
							switched_currency: $upgradeAuthCurrencyEl.length
								? $upgradeAuthCurrencyEl.val()
								: "",
							urm_zone_id: $upgradeAuthCurrencyEl.length
								? $upgradeAuthCurrencyEl.data("urm-zone-id")
								: "",
							tax_rate: taxDetailsElAuth.length
								? taxDetailsElAuth.data("tax-rate")
								: "",
							tax_calculation_method: taxDetailsElAuth.length
								? taxDetailsElAuth.data(
										"tax-calculation-method"
									)
								: ""
						},
						{
							success: function (response) {
								if (response.success) {
									ur_membership_frontend_utils.show_success_message(
										response.data.message
									);
									var prepare_members_data = {
										payment_method: selected_pg,
										username: response.data.username
									};

									ur_membership_ajax_utils.handle_update_response(
										response,
										prepare_members_data
									);
								} else {
									ur_membership_frontend_utils.show_failure_message(
										response.data.message
									);
									$(document)
										.find(".swal2-confirm")
										.find("span")
										.removeClass("urm-spinner");
								}
							},
							failure: function (xhr, statusText) {
								ur_membership_frontend_utils.show_failure_message(
									user_registration_params.network_error +
										"(" +
										statusText +
										")"
								);
								$(document)
									.find(".swal2-confirm")
									.find("span")
									.removeClass("urm-spinner");
							},
							complete: function () {
								ur_membership_frontend_utils.toggleSaveButtons(
									false,
									btn
								);
							}
						}
					);
				}
			});
		},
		handle_authorize_multiple_purchase: function (
			submittedData,
			selected_membership_id,
			selected_pg,
			btn,
			type
		) {
			var data = {
				selected_membership_id: selected_membership_id,
				selected_pg: selected_pg,
				btn: btn,
				type: type
			};

			var taxDetailsElAuthMultiple = $(document).find(
				"#ur-tax-details"
			);
			var $authMultipleCurrencyEl = $(
				"#ur-local-currency-switch-currency"
			);

			$(document).trigger("urm_before_multiple_membership_submit", {
				data: data,
				onComplete: function (data) {
					ur_membership_ajax_utils.send_data(
						{
							_wpnonce: urmf_data.upgrade_membership_nonce,
							action: "user_registration_membership_add_multiple_membership",
							selected_membership_id: data.selected_membership_id,
							selected_pg: data.selected_pg,
							ur_authorize_data: data.ur_authorize_data,
							form_data: submittedData.form_data,
							coupon: submittedData.coupon,
							form_id: submittedData.form_id,
							type: data.type,
							switched_currency: $authMultipleCurrencyEl.length
								? $authMultipleCurrencyEl.val()
								: "",
							urm_zone_id: $authMultipleCurrencyEl.length
								? $authMultipleCurrencyEl.data("urm-zone-id")
								: "",
							tax_rate: taxDetailsElAuthMultiple.length
								? taxDetailsElAuthMultiple.data("tax-rate")
								: "",
							tax_calculation_method: taxDetailsElAuthMultiple.length
								? taxDetailsElAuthMultiple.data(
										"tax-calculation-method"
									)
								: ""
						},
						{
							success: function (response) {
								if (response.success) {
									ur_membership_frontend_utils.show_success_message(
										response.data.message
									);
									var prepare_members_data = {
										payment_method: selected_pg,
										username: response.data.username
									};

									ur_membership_ajax_utils.handle_update_response(
										response,
										prepare_members_data
									);
								} else {
									ur_membership_frontend_utils.show_failure_message(
										response.data.message
									);
									$(document)
										.find(".swal2-confirm")
										.find("span")
										.removeClass("urm-spinner");
								}
							},
							failure: function (xhr, statusText) {
								ur_membership_frontend_utils.show_failure_message(
									user_registration_params.network_error +
										"(" +
										statusText +
										")"
								);
								$(document)
									.find(".swal2-confirm")
									.find("span")
									.removeClass("urm-spinner");
							},
							complete: function () {
								ur_membership_frontend_utils.toggleSaveButtons(
									false,
									btn
								);
							}
						}
					);
				}
			});
		},

		authorize_net_container_html: function () {
			return (
				"" +
				'<div id="authorize-net-container" class="urm-d-none membership-only authorize-net-container">' +
				'<div data-field-id="authorizenet_gateway" class="ur-field-item field-authorize_net_gateway" data-ref-id="authorizenet_gateway" data-field-pattern-enabled="0" data-field-pattern-value=" " data-field-pattern-message=" ">' +
				'<div class="form-row" id="authorizenet_gateway_field"><label class="ur-label" for="Authorize.net">Authorize.net <abbr class="required" title="required">*</abbr></label><p></p>' +
				'<div id="user_registration_authorize_net_gateway" data-gateway="authorize_net" class="input-text" conditional_rules="">' +
				'<div class="ur-field-row">' +
				'<div class="user-registration-authorize-net-card-number">' +
				'<input type="text" id="user_registration_authorize_net_card_number" name="user_registration_authorize_net_card_number" maxlength="16" placeholder="411111111111111" class="widefat ur-anet-sub-field user_registration_authorize_net_card_number"><br>' +
				'<label class="user-registration-sub-label">Card Number</label></div>' +
				"</div>" +
				'<div class="ur-field-row clearfix">' +
				'<div class="user-registration-authorize-net-expiration user-registration-one-half">' +
				'<input type="text" inputmode="numeric" autocomplete="cc-exp" id="user_registration_authorize_net_expiration" name="user_registration_authorize_net_expiration" placeholder="MM / YY" maxlength="9" class="widefat ur-anet-sub-field user_registration_authorize_net_expiration"><label class="user-registration-sub-label">Expiration</label>' +
				"</div>" +
				'<div class="user-registration-authorize-net-cvc user-registration-one-half last">' +
				'<input type="text" id="user_registration_authorize_net_card_code" name="user_registration_authorize_net_card_code" placeholder="900" maxlength="4" class="widefat ur-anet-sub-field user_registration_authorize_net_card_code"><br>' +
				'<label class="user-registration-sub-label">CVC</label>' +
				"</div>" +
				"</div>" +
				"</div>" +
				"</div></div></div>"
			);
		},
		prepare_pg_html: function (gateways) {
			var gateway_html = "";
			$.each(gateways, function (index, gateway) {
				var gateway_value = gateway.toLowerCase();
				gateway_html +=
					'<label class="ur_membership_input_label ur-label" for="ur-membership-' +
					gateway_value +
					'">' +
					'<input class="ur_membership_input_class pg-list" ' +
					'data-key-name="ur-payment-method" ' +
					'id="ur-membership-' +
					gateway_value +
					'" ' +
					'type="radio" ' +
					'name="urm_payment_method" ' +
					(index === 0 && gateways && gateways.length === 1
						? "checked"
						: "") +
					" " +
					"required " +
					'value="' +
					gateway_value +
					'">' +
					'<span class="ur-membership-duration">' +
					gateway_value.charAt(0).toUpperCase() +
					gateway_value.slice(1) +
					"</span>" +
					"</label>";
			});
			return gateway_html;
		},
		prepare_renew_membership_html: function (gateways) {
			return (
				'<div class="membership-upgrade-container">' +
				'<div class="ur_membership_registration_container">' +
				'<div class="ur_membership_frontend_input_container urm_hidden_payment_container ur_payment_gateway_container">' +
				'<div id="payment-gateway-body" class="ur_membership_frontend_input_container">' +
				ur_membership_ajax_utils.prepare_pg_html(gateways) +
				'<span id="payment-gateway-notice" class="notice_red"></span>' +
				"</div>" +
				"</div>" +
				'<div class="ur_membership_frontend_input_container">' +
				'<div class="stripe-container urm-d-none">' +
				'<button type="button" class="stripe-card-indicator ur-stripe-element-selected" id="credit_card">Credit Card</button>' +
				'<div class="stripe-input-container"><div id="card-element"></div></div>' +
				"</div>" +
				"</div>" +
				ur_membership_ajax_utils.authorize_net_container_html() +
				"</div>" +
				'<span id="upgrade-membership-notice"></span>' +
				"</div>"
			);
		},
		prepare_upgrade_membership_html: function (data) {
			var membership_title = $("#membership-title").text() || "";
			var options_html = "",
				gateways = urmf_data.membership_gateways || [];

			//plans html
			$.each(data, function (key, membership) {
				var id = membership.ID || "",
					title = membership.title || "",
					type = membership.type || "",
					period = membership.period || "",
					calculated_amount = membership.calculated_amount || "",
					amount = membership.amount || "",
					active_pg = membership.active_payment_gateways || "{}";

				options_html +=
					'<label class="upgrade-membership-label" for="ur-membership-select-membership-' +
					id +
					'">' +
					'<input class="ur_membership_input_class ur_membership_radio_input ur-frontend-field" ' +
					'id="ur-membership-select-membership-' +
					id +
					'" ' +
					'type="radio" ' +
					'name="urm_membership" ' +
					'data-label="' +
					title +
					'" ' +
					'required="required" ' +
					'value="' +
					id +
					'" ' +
					"data-urm-pg='" +
					active_pg +
					"' " +
					'data-urm-pg-type="' +
					type +
					'" ' +
					'data-urm-pg-calculated-amount="' +
					calculated_amount +
					'">' +
					'<span class="ur-membership-duration">' +
					title +
					"</span>" +
					'<span class="ur-membership-duration"> - ' +
					period +
					"</span>" +
					"</label>";
			});

			return (
				'<div class="membership-upgrade-container">' +
				"<span>Your current Plan is <b>" +
				membership_title +
				"</b></span>" +
				'<div class="upgrade-plan-container">' +
				'<span class="ur-upgrade-label">Select Plan</span>' +
				'<div id="upgradable-plans">' +
				options_html +
				"</div>" +
				"</div>" +
				'<div class="ur_membership_registration_container urm-d-none">' +
				'<div class="ur_membership_frontend_input_container urm_hidden_payment_container ur_payment_gateway_container urm-d-none">' +
				'<span class="ur-upgrade-label ur-label required">Select Payment Gateway</span>' +
				'<div id="payment-gateway-body" class="ur_membership_frontend_input_container">' +
				ur_membership_ajax_utils.prepare_pg_html(gateways) +
				'<span id="payment-gateway-notice" class="notice_red"></span>' +
				"</div>" +
				"</div>" +
				'<div class="ur_membership_frontend_input_container">' +
				'<div class="stripe-container urm-d-none">' +
				'<button type="button" class="stripe-card-indicator ur-stripe-element-selected" id="credit_card">Credit Card</button>' +
				'<div class="stripe-input-container"><div id="card-element"></div></div>' +
				"</div>" +
				"</div>" +
				ur_membership_ajax_utils.authorize_net_container_html() +
				"</div>" +
				"</div>"
			);
		},
		prepare_intended_membership_purchase_html: function (membership) {
			var membership_title = $("#membership-title").text() || "";
			var options_html = "",
				gateways = urmf_data.membership_gateways || [];

			//plans html
			var id = membership.ID || "",
				title = membership.title || "",
				type = membership.type || "",
				period = membership.period || "",
				calculated_amount = membership.calculated_amount || "",
				amount = membership.amount || "",
				active_pg = membership.active_payment_gateways || "{}";

			options_html +=
				'<label class="upgrade-membership-label" for="ur-membership-select-membership-' +
				id +
				'">' +
				'<input class="ur_membership_input_class ur_membership_radio_input ur-frontend-field" ' +
				'id="ur-membership-select-membership-' +
				id +
				'" ' +
				'type="radio" ' +
				'name="urm_membership" ' +
				'data-label="' +
				title +
				'" ' +
				'required="required" ' +
				'value="' +
				id +
				'" ' +
				"data-urm-pg='" +
				active_pg +
				"' " +
				'data-urm-pg-type="' +
				type +
				'" ' +
				'data-urm-pg-calculated-amount="' +
				calculated_amount +
				'">' +
				'<span class="ur-membership-duration">' +
				title +
				"</span>" +
				'<span class="ur-membership-duration"> - ' +
				period +
				"</span>" +
				"</label>";

			return (
				'<div class="membership-upgrade-container">' +
				"<span>Your current Plan is <b>" +
				membership_title +
				"</b></span>" +
				'<div class="upgrade-plan-container">' +
				'<span class="ur-upgrade-label">Select Plan</span>' +
				'<div id="upgradable-plans">' +
				options_html +
				"</div>" +
				"</div>" +
				'<div class="ur_membership_registration_container urm-d-none">' +
				'<div class="ur_membership_frontend_input_container urm_hidden_payment_container ur_payment_gateway_container urm-d-none">' +
				'<span class="ur-upgrade-label ur-label required">Select Payment Gateway</span>' +
				'<div id="payment-gateway-body" class="ur_membership_frontend_input_container">' +
				ur_membership_ajax_utils.prepare_pg_html(gateways) +
				'<span id="payment-gateway-notice" class="notice_red"></span>' +
				"</div>" +
				"</div>" +
				'<div class="ur_membership_frontend_input_container">' +
				'<div class="stripe-container urm-d-none">' +
				'<button type="button" class="stripe-card-indicator ur-stripe-element-selected" id="credit_card">Credit Card</button>' +
				'<div class="stripe-input-container"><div id="card-element"></div></div>' +
				"</div>" +
				"</div>" +
				ur_membership_ajax_utils.authorize_net_container_html() +
				"</div>" +
				"</div>"
			);
		},
		/**
		 * Handles the response based on the payment method selected.
		 *
		 * @param {Object} response - The response data from the server.
		 * @param {Object} prepare_members_data - The data for preparing members.
		 */
		handle_update_response: function (response, prepare_members_data) {
			switch (prepare_members_data.payment_method) {
				case "paypal":
					ur_membership_frontend_utils.show_success_message(
						response.data.message
					);
					if (
						response.data.pg_data &&
						response.data.pg_data.payment_url
					) {
						window.location.replace(
							response.data.pg_data.payment_url
						);
					} else {
						ur_membership_frontend_utils.show_failure_message(
							(response.data.pg_data &&
								response.data.pg_data.payment_error) ||
								"PayPal redirect failed. Please check your PayPal configuration."
						);
					}
					break;
				case "stripe":
					stripe_settings.handle_stripe_response(
						response,
						prepare_members_data,
						{ data: {} }
					);
					break;
				case "mollie":
					ur_membership_frontend_utils.show_success_message(
						response.data.message
					);
					window.location.replace(response.data.pg_data.payment_url);
					break;
				case "authorize":
					window.location.replace(response.data.redirect);
					break;
				case "free":
					var freeRedirectUrl = $("#urm-redirect-url").val() || "";
					if (freeRedirectUrl) {
						window.location.replace(freeRedirectUrl);
					}
					break;
				default:
					ur_membership_ajax_utils.show_bank_response(
						response,
						{
							username: prepare_members_data.username,
							payment_method: prepare_members_data.payment_method
						},
						{
							data: {}
						}
					);
					break;
			}
		},
		handle_renewal_response: function (response, prepare_members_data) {
			switch (prepare_members_data.payment_method) {
				case "paypal":
					ur_membership_frontend_utils.show_success_message(
						response.data.message
					);
					if (
						response.data.pg_data &&
						response.data.pg_data.payment_url
					) {
						window.location.replace(
							response.data.pg_data.payment_url
						);
					} else {
						ur_membership_frontend_utils.show_failure_message(
							(response.data.pg_data &&
								response.data.pg_data.payment_error) ||
								"PayPal redirect failed. Please check your PayPal configuration."
						);
					}
					break;
				case "stripe":
					stripe_settings.handle_stripe_response(
						response,
						prepare_members_data,
						{ data: {} }
					);
					break;
				default:
					ur_membership_ajax_utils.show_bank_response(
						response,
						{
							username: prepare_members_data.username,
							payment_method: prepare_members_data.payment_method
						},
						{
							data: {}
						}
					);
					break;
			}
		},
		cancel_delayed_subscription: function (btn) {
			ur_membership_frontend_utils.toggleSaveButtons(true, btn);
			ur_membership_frontend_utils.append_spinner(btn);

			this.send_data(
				{
					_wpnonce: urmf_data.upgrade_membership_nonce,
					action: "user_registration_membership_cancel_upcoming_subscription"
				},
				{
					success: function (response) {
						if (response.success) {
							Swal.close();
							ur_membership_frontend_utils.show_success_message(
								response.data.message
							);
						} else {
							ur_membership_frontend_utils.show_failure_message(
								response.data.message
							);
						}
					},
					failure: function (xhr, statusText) {
						ur_membership_frontend_utils.show_failure_message(
							urmf_data.labels.network_error +
								" (" +
								xhr.statusText +
								")"
						);
					},
					complete: function () {
						ur_membership_frontend_utils.remove_spinner(btn);
						ur_membership_frontend_utils.toggleSaveButtons(
							false,
							btn
						);
					}
				}
			);
		}
	};
	var form_object = {
		hide_loader: function (form_id) {
			var $registration_form = $("#user-registration-form-" + form_id);
			$registration_form
				.find(".ur-submit-button")
				.find("span")
				.removeClass("ur-front-spinner");
			$registration_form
				.find("form")
				.find(".ur-submit-button")
				.prop("disabled", false);
		}
	};
	var stripe_settings = {
		show_stripe_error: function (message) {
			if (
				$membership_registration_form.find("#stripe-errors").length > 0
			) {
				$membership_registration_form
					.find('#stripe-errors')
					.text(message)
					.show();
			} else {
				var $errorLabel = $('<label id="stripe-errors" class="user-registration-error" role="alert"></label>');
				$errorLabel.text(message);
				$membership_registration_form
					.find('.stripe-container')
					.closest('.ur_membership_frontend_input_container')
					.append($errorLabel);
			}
		},
		init: function (is_upgrading) {
			elements = stripe_settings.setupElements();
			$membership_registration_form = is_upgrading
				? $(".membership-upgrade-container")
				: $("#ur-membership-registration");
			this.triggerInputChange();
		},
		triggerInputChange: function () {
			elements.card.addEventListener("change", function (e) {
				stripe_mode_validated = false;
				validated_stripe_pm_id = null;
				// Genuine Stripe change events always carry a boolean `complete`;
				// synthetic events emitted from validate_membership_form don't,
				// and must not overwrite the tracked card state.
				if (typeof e.complete !== "undefined") {
					stripe_card_state.complete = e.complete;
					stripe_card_state.error = e.error || null;
				}
				if (e.error) {
					stripe_settings.show_stripe_error(e.error.message);
				} else {
					if (
						$membership_registration_form.find("#stripe-errors")
							.length > 0
					) {
						$membership_registration_form
							.find("#stripe-errors")
							.remove();
					}
				}
			});
		},
		setupElements: function () {
			var stripe = Stripe(urmf_data.stripe_publishable_key), //take this from global variable
				elements = stripe.elements();

			var style = {
				base: {
					color: "#32325d",
					fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
					fontSmoothing: "antialiased",
					fontSize: "14px",
					"::placeholder": {
						color: "#8f9194"
					}
				},
				invalid: {
					color: "#fa755a",
					iconColor: "#fa755a"
				}
			};

			var card = elements.create("card", {
				style: style,
				hidePostalCode:
					urmf_data.urm_hide_stripe_card_postal_code == "1"
						? true
						: false
			});
			var idealBank = elements.create("idealBank", { style: style });

			card.mount("#card-element");
			return {
				stripe: stripe,
				card: card,
				ideal: idealBank,
				clientSecret: ""
			};
		},

		handle_stripe_response: function (
			response,
			prepare_members_data,
			form_response
		) {
			ur_membership_frontend_utils.show_payment_processing_overlay();

			if (response.data.pg_data.type === "paid") {
				this.handle_one_time_payment(
					response,
					prepare_members_data,
					form_response
				);
			} else {
				this.handle_recurring_payment(response, {
					paymentElements: elements,
					user_id: response.data.member_id,
					response_data: response,
					prepare_members_data: prepare_members_data,
					form_response: form_response,
					team_id: response.data.team_id ? response.data.team_id : ""
				})
					.then(function () {
						ur_membership_frontend_utils.show_success_message(
							response.data.message
						);
						form_object.hide_loader(form_response.form_id);
					})
					.catch(function () {
						form_object.hide_loader(form_response.form_id);
					});
			}
		},

		handle_one_time_payment: function (
			response,
			prepare_members_data,
			form_response
		) {
			ur_membership_frontend_utils.show_payment_processing_overlay();

			return elements.stripe
				.confirmCardPayment(response.data.pg_data.client_secret, {
					payment_method: {
						card: elements.card
					}
				})
				.then(function (result) {
					var button = $(".membership_register_button");
					ur_membership_frontend_utils.toggleSaveButtons(
						true,
						button
					);
					ur_membership_frontend_utils.append_spinner(button);
					stripe_settings.update_order_status(
						result,
						response,
						prepare_members_data,
						form_response
					);
				});
		},
		update_order_status: function (
			result,
			response,
			prepare_members_data,
			form_response
		) {
			ur_membership_ajax_utils.send_data(
				{
					_wpnonce: urmf_data._confirm_payment_nonce,
					action: "user_registration_membership_confirm_payment",
					members_data: JSON.stringify(prepare_members_data),
					member_id: response.data.member_id,
					payment_status: result.error ? "failed" : "succeeded",
					form_response: JSON.stringify(form_response.data),
					payment_result: result,
					selected_membership_id: response.data.selected_membership_id
						? response.data.selected_membership_id
						: "",
					current_membership_id: response.data.current_membership_id
						? response.data.current_membership_id
						: "",
					team_id: response.data.team_id ? response.data.team_id : "",
					order_id: response.data.order_id
						? response.data.order_id
						: ""
				},
				{
					success: function (response) {
						if (response.success) {
							ur_membership_frontend_utils.hide_payment_processing_overlay();
							ur_membership_frontend_utils.show_success_message(
								response.data.message
							);
							if (
								response.data.is_upgrading ||
								response.data.is_renewing ||
								response.data.is_purchasing_multiple
							) {
								var thank_you_data = {
									username: prepare_members_data.username,
									message: response.data.message
								};
								if (response.data.is_upgrading) {
									thank_you_data.is_upgrading =
										response.data.is_upgrading;
								} else if (response.data.is_renewing) {
									thank_you_data.is_renewing =
										response.data.is_renewing;
								}

								var upgradeRedirectUrl =
									$("#urm-redirect-url").val() || "";

								ur_membership_ajax_utils.show_default_response(
									upgradeRedirectUrl,
									thank_you_data
								);
							} else {
								var stripeRedirectUrl =
									$("#urm-redirect-url").val() || "";

								ur_membership_ajax_utils.show_default_response(
									stripeRedirectUrl,
									{
										username: prepare_members_data.username,
										transaction_id:
											result.paymentIntent &&
											result.paymentIntent.id
												? result.paymentIntent.id
												: result.id
									}
								);
							}
						} else {
							ur_membership_frontend_utils.hide_payment_processing_overlay();
							stripe_settings.show_stripe_error(
								response.data.message
							);
							form_object.hide_loader(form_response.form_id);
						}
					},
					failure: function (xhr, statusText) {
						ur_membership_frontend_utils.hide_payment_processing_overlay();
						ur_membership_frontend_utils.show_failure_message(
							urmf_data.labels.i18n_error +
								"(" +
								xhr.responseJSON.data.message +
								")"
						);
					},
					complete: function () {
						var swal_btn = $(".swal2-confirm ");
						form_object.hide_loader(form_response.form_id);
						ur_membership_frontend_utils.toggleSaveButtons(
							false,
							swal_btn
						);
						ur_membership_frontend_utils.remove_spinner(swal_btn);
					}
				}
			);
		},
		handle_recurring_payment: function (response, data) {
			return Promise.resolve(
				$.extend({}, data, {
					customer_id: response.data.pg_data.stripe_cus_id,
					selected_membership_id: response.data.selected_membership_id
						? response.data.selected_membership_id
						: "",
					current_membership_id: response.data.current_membership_id
						? response.data.current_membership_id
						: ""
				})
			)
				.then(stripe_settings.createPaymentMethod)
				.then(stripe_settings.createSubscription)
				.then(stripe_settings.handleCustomerActionRequired)
				.then(stripe_settings.handleOnComplete)
				.catch(function (message, error) {
					ur_membership_frontend_utils.hide_payment_processing_overlay();
					stripe_settings.update_order_status(
						{ error: {} },
						response,
						data.prepare_members_data,
						data.form_response
					);
					return Promise.reject(error);
				});
		},
		/**
		 * Create payment method.
		 *
		 * @param {object} data Contains Stripe, card, paymentItems, current form selector and customerId.
		 */
		createPaymentMethod: function (data) {
			return new Promise(function (resolve, reject) {
				var button = $(".membership_register_button");
				ur_membership_frontend_utils.toggleSaveButtons(true, button);
				ur_membership_frontend_utils.append_spinner(button);
				// Simulating async process
				data.paymentElements.stripe
					.createPaymentMethod({
						type: "card",
						card: data.paymentElements.card
					})
					.then(function (result) {
						if (result.error) {
							reject(result.error.message, result); // Reject the promise with the error
						} else {
							resolve(
								$.extend({}, data, {
									payment_method_id: result.paymentMethod.id
								})
							);
						}
					})
					.catch(function (error) {
						reject(error, result); // Catch any unexpected errors and reject
					});
			});
		},

		/**
		 * Create subscription.
		 *
		 * @param {object} dataContains Stripe, card, formid, paymentItems, current form selector, customerId and paymentMethodId.
		 */
		createSubscription: function (data) {
			return new Promise(function (resolve, reject) {
				ur_membership_ajax_utils.send_data(
					{
						_wpnonce: urmf_data._confirm_payment_nonce,
						action: "user_registration_membership_create_stripe_subscription",
						member_id: data.user_id,
						customer_id: data.customer_id,
						payment_method_id: data.payment_method_id,
						form_response: JSON.stringify(data.form_response.data),
						selected_membership_id: data.selected_membership_id
							? data.selected_membership_id
							: "",
						current_membership_id: data.current_membership_id
							? data.current_membership_id
							: "",
						team_id: data.team_id ? data.team_id : ""
					},
					{
						success: function (response) {
							if (response.success) {
								var paymentIntent =
									response.data.subscription.latest_invoice
										.payment_intent;

								if (response.error) {
									var message = response.error.message;
									reject(message, data);
									return;
								}

								if (
									"trialing" !==
									response.data.subscription.status
								) {
									if (
										paymentIntent &&
										"requires_payment_method" ===
											paymentIntent.status
									) {
										var message = "Your card was declined";
										reject(response, message);
									}
								}

								resolve(
									$.extend({}, data, {
										subscription:
											response.data.subscription,
										message: response.data.message
									})
								);
							} else {
								form_object.hide_loader(data.form_id);
								reject(response, message);
							}
						},
						failure: function (xhr, statusText) {
							ur_membership_frontend_utils.show_failure_message(
								urmf_data.labels.network_error +
									"(" +
									statusText +
									")"
							);
						}
					}
				);
			});
		},

		/**
		 * Handle customer action if it is required.
		 *
		 * @param {object} data Contains stripe, card, formid, paymentItems, form_selector, customerId, paymentMethodId and subscription.
		 *
		 */
		handleCustomerActionRequired: function (data) {
			ur_membership_frontend_utils.show_payment_processing_overlay();

			return new Promise(function (resolve, reject) {
				var subscription = data.subscription || {};
				var resolvePayload = {
					subscription: subscription,
					response_data: data.response_data,
					message: data.message,
					prepare_members_data: data.prepare_members_data,
					form_response: data.form_response
				};

				if (
					subscription.status === "active" ||
					subscription.status === "trialing"
				) {
					ur_membership_frontend_utils.hide_payment_processing_overlay();
					resolve(resolvePayload);
					return;
				}

				// Only an expanded PaymentIntent with a client_secret allows SCA / 3D Secure on the client.
				var latestInvoice = subscription.latest_invoice || {};
				var paymentIntent = latestInvoice.payment_intent;
				var clientSecret =
					paymentIntent && typeof paymentIntent === "object"
						? paymentIntent.client_secret
						: null;
				var piStatus =
					paymentIntent && typeof paymentIntent === "object"
						? paymentIntent.status
						: null;
				var needsAction =
					piStatus === "requires_action" ||
					piStatus === "requires_source_action" ||
					piStatus === "requires_confirmation";

				if (clientSecret && needsAction) {
					data.paymentElements.stripe
						.confirmCardPayment(clientSecret)
						.then(function (result) {
							ur_membership_frontend_utils.hide_payment_processing_overlay();
							if (result.error) {
								reject(result.error.message, data);
								return;
							}

							if (
								result.paymentIntent &&
								"succeeded" === result.paymentIntent.status
							) {
								subscription.status = "active";
								resolve({
									subscription: subscription,
									form_id: data.form_response.form_id,
									response_data: data.response_data,
									prepare_members_data:
										data.prepare_members_data,
									form_response: data.form_response,
									three_d_secure: true
								});
							} else {
								reject(
									"Unable to complete the payment.",
									data
								);
							}
						})
						.catch(function (error) {
							ur_membership_frontend_utils.hide_payment_processing_overlay();
							reject(
								error && error.message
									? error.message
									: "Unable to complete the payment.",
								data
							);
						});
					return;
				}

				ur_membership_frontend_utils.hide_payment_processing_overlay();
				resolve(resolvePayload);
			});
		},

		/**
		 * Handle subscription complete.
		 *
		 * @param {object} data  Contains stripe, card, formid, paymentItems, form_selector, customerId, paymentMethodId and subscription.
		 */
		handleOnComplete: function (data) {
			var is_upgrading =
					data.response_data.data.is_upgrading !== undefined
						? data.response_data.data.is_upgrading
						: false,
				is_renewing =
					data.response_data.data.is_renewing !== undefined
						? data.response_data.data.is_renewing
						: false,
				is_purchasing_multiple =
					data.response_data.data.is_purchasing_multiple !== undefined
						? data.response_data.data.is_purchasing_multiple
						: false,
				is_three_d_secure =
					undefined !== data.three_d_secure
						? data.three_d_secure
						: false;

			if (
				is_upgrading ||
				is_renewing ||
				is_purchasing_multiple ||
				is_three_d_secure
			) {
				stripe_settings.update_order_status(
					data.subscription,
					data.response_data,
					data.prepare_members_data,
					data.form_response
				);
			}

			if (
				data.subscription &&
				(data.subscription.status === "active" ||
					data.subscription.status === "trialing") &&
				!is_upgrading &&
				!is_renewing &&
				!is_purchasing_multiple &&
				!data.three_d_secure
			) {
				ur_membership_frontend_utils.show_form_success_message(
					data.form_response,
					{
						username: data.prepare_members_data.username,
						transaction_id: data.subscription.id
					}
				);
			}
			return { success: true };
		}
	};
	var ur_membership_currency_gateway_utils = {
		gateway_labels: {
			paypal: "PayPal",
			mollie: "Mollie",
			authorize: "Authorize.Net"
		},
		get_unsupported_gateways: function (currency) {
			var unsupported = [];

			if (!currency || !urmf_data.supported_currencies) {
				return unsupported;
			}

			$.each(urmf_data.supported_currencies, function (
				gateway,
				currencies
			) {
				if ($.inArray(currency, currencies) === -1) {
					unsupported.push(gateway);
				}
			});

			return unsupported;
		},
		get_active_currency: function () {
			var $switcher = $("#ur-local-currency-switch-currency"),
				$checkedMembership = $('input[name="urm_membership"]:checked');

			if ($switcher.length) {
				var switcherCurrency = $switcher.val();

				if (!$checkedMembership.length) {
					return switcherCurrency;
				}

				// A membership only charges in the switcher's currency if it has a matching zone.
				var localCurrencyDetails =
					$checkedMembership.data("urm-local-currency-details") ||
					{};

				if (localCurrencyDetails.hasOwnProperty(switcherCurrency)) {
					return switcherCurrency;
				}

				return urmf_data.currency || switcherCurrency;
			}

			var checkoutCurrency = $checkedMembership.data("currency");

			return checkoutCurrency || urmf_data.currency || "";
		},
		toggle_submit: function ($form_context, blocked) {
			var submit_selector = ".ur-submit-button, .urm-update-membership-button",
				$submit = $form_context.find(submit_selector);

			if (!$submit.length) {
				$submit = $(submit_selector);
			}

			if (blocked) {
				$submit
					.prop("disabled", true)
					.addClass("urm-submit-currency-blocked")
					.data("urm-currency-gateway-blocked", true);
			} else if ($submit.data("urm-currency-gateway-blocked")) {
				$submit
					.prop("disabled", false)
					.removeClass("urm-submit-currency-blocked")
					.data("urm-currency-gateway-blocked", false);
			}
		},
		build_message: function (currency, unsupported) {
			var self = this,
				labels = $.map(unsupported, function (gateway) {
					return self.gateway_labels[gateway] || gateway;
				});

			return (
				currency +
				" " +
				urmf_data.labels.i18n_currency_not_supported_by +
				" " +
				labels.join(", ") +
				". " +
				urmf_data.labels.i18n_choose_another_gateway_or_currency
			);
		},
		build_no_gateway_message: function (currency) {
			return (
				urmf_data.labels.i18n_no_gateway_available_for_currency ||
				""
			).replace("%s", currency);
		},
		evaluate: function ($context) {
			var self = this,
				$form_context =
					$context && $context.length
						? $context
						: $(
								"#ur-membership-registration, .membership-upgrade-container"
							),
				currency = self.get_active_currency(),
				unsupported = self.get_unsupported_gateways(currency),
				$notice = $("#payment-gateway-notice"),
				$checked = $('input[name="urm_payment_method"]:checked'),
				checkedValue = $checked.val(),
				submit_blocked = false;

			$(".ur_payment_gateway_container input.pg-list").each(function () {
				var $input = $(this),
					$label = $input.closest("label"),
					$container = $input.closest(".ur_payment_gateway_container"),
					gateway = $input.val(),
					isUnsupported = $.inArray(gateway, unsupported) !== -1;

				if ($container.hasClass("urm-d-none")) {
					return;
				}

				if (isUnsupported) {
					if (!$label.hasClass("urm-d-none")) {
						$label.addClass("urm-d-none urm-pg-currency-hidden");
						$input.prop("disabled", true);
					}
				} else if ($label.hasClass("urm-pg-currency-hidden")) {
					$label
						.removeClass("urm-d-none")
						.removeClass("urm-pg-currency-hidden");
					$input.prop("disabled", false);
				}
			});

			if (checkedValue && $.inArray(checkedValue, unsupported) !== -1) {
				$checked.prop("checked", false);
			}

			var $visiblePgContainer = $(
					".ur_payment_gateway_container"
				).not(".urm-d-none"),
				$remainingGateways = $visiblePgContainer
					.find('label[for^="ur-membership-"]')
					.not(".urm-d-none");

			if ($visiblePgContainer.length && $remainingGateways.length === 0) {
				$notice.text(self.build_no_gateway_message(currency));
				submit_blocked = true;
			} else {
				$notice.text("");
			}

			self.toggle_submit($form_context, submit_blocked);
		}
	};
	var register_events = {
		init: function () {
			$(document).on(
				"change",
				'input[name="urm_payment_method"]',
				function () {
					var selected_method = $(this).val(),
						stripe_container = $(".stripe-container"),
						stripe_error_container = $("#stripe-errors");

					ur_membership_currency_gateway_utils.evaluate(
						$(this).closest(
							"#ur-membership-registration, .membership-upgrade-container"
						)
					);

					var authorize_container = $(".authorize-net-container");
					var authorize_error_container = $("#authorize-errors");

					stripe_container.addClass("urm-d-none");
					stripe_error_container.remove();

					authorize_container.addClass("urm-d-none");
					authorize_error_container.remove();
					authorize_container
						.find(
							"#user_registration_authorize_net_card_number, #user_registration_authorize_net_expiration, #user_registration_authorize_net_card_code"
						)
						.val("");
					authorize_container.find(".ur-authorize-net-errors").remove();
					authorize_container
						.find("#authorizenet_gateway_field")
						.removeClass("ur-authorize-net-error");
					authorize_container
						.find(".ur-anet-sub-field")
						.removeClass("ur-input-border-red");

					var stripeAlreadyReady =
						selected_method === "stripe" &&
						elements &&
						elements.card;
					if (!stripeAlreadyReady) {
						elements = {};
					}

					if (selected_method === "stripe") {
						if (urmf_data.stripe_publishable_key.length == 0) {
							ur_membership_frontend_utils.show_failure_message(
								urmf_data.labels
									.i18n_incomplete_stripe_setup_error
							);
							return;
						}
						stripe_container.removeClass("urm-d-none");
						if (!stripeAlreadyReady) {
							stripe_settings.init();
						}
					}
					if (selected_method === "authorize") {
						authorize_container.removeClass("urm-d-none");
					}
				}
			);

			//activate payment gateways

			$(document).on(
				"change",
				'input[name="urm_membership"]',
				function () {
					$(".ur_membership_registration_container").removeClass(
						"urm-d-none"
					);
					// $('#ur-local-currency-switch-currency').trigger('change');
					// $('.ur-field-address-country').trigger('change');
					// clear coupon total notice
					$("#total-input-notice").text("");

					var urm_payment_gateways = $(this).data("urm-pg"),
						urm_payment_type = $(this).data("urm-pg-type"),
						urm_pg_container = $(".ur_payment_gateway_container"),
						$form_context = $(this).closest(
							"#ur-membership-registration, .membership-upgrade-container"
						),
						urm_pg_container_scoped = $form_context.length
							? $form_context.find(
									".ur_payment_gateway_container"
								)
							: urm_pg_container,
						urm_pg_inputs = urm_pg_container.find("input"),
						urm_hidden_pg_containers = $(
							".urm_hidden_payment_container"
						),
						stripe_container = $(".stripe-container"),
						stripe_error_container = $("#stripe-errors"),
						upgrade_error_container = $(
							"#upgrade-membership-notice"
						),
						urm_default_pg = $(this).data("urm-default-pg"),
						hasCouponLink = $(this).data("has-coupon-link");
					if ("yes" === hasCouponLink) {
						$(document).find("#ur_coupon_container").show();
					} else {
						$(document).find("#ur_coupon_container").hide();
					}

					var authorize_container = $(".authorize-net-container");
					var authorize_error_container = $("#authorize-errors");

					authorize_error_container.remove();

					stripe_error_container.remove();
					upgrade_error_container.text("");

					// Reset the Authorize.Net card on every membership switch; re-shown below only if it is the active gateway.
					authorize_container.addClass("urm-d-none");
					authorize_container
						.find(
							"#user_registration_authorize_net_card_number, #user_registration_authorize_net_expiration, #user_registration_authorize_net_card_code"
						)
						.val("");
					authorize_container.find(".ur-authorize-net-errors").remove();
					authorize_container
						.find("#authorizenet_gateway_field")
						.removeClass("ur-authorize-net-error");
					authorize_container
						.find(".ur-anet-sub-field")
						.removeClass("ur-input-border-red");

					//Selects a default payment gateway. Needs to be updated for translation.
					if (
						urm_default_pg &&
						urm_default_pg.toLowerCase() === urm_default_pg
					) {
						$(this)
							.closest("#ur-membership-registration")
							.find("#ur-membership-" + urm_default_pg)
							.prop("checked", true)
							.trigger("change");

						if (
							urm_default_pg.toLowerCase() === "stripe" &&
							!(elements && elements.card)
						) {
							stripe_settings.init();
						}

						if (urm_default_pg.toLowerCase() !== "authorize") {
							authorize_container.addClass("urm-d-none");
						}
					} else {
						$('input[name="urm_payment_method"]').prop(
							"checked",
							false
						);
						stripe_container.addClass("urm-d-none");
					}

					urm_hidden_pg_containers.addClass("urm-d-none");
					// Total should always reflect the selected plan (including $0.00 for a
					// free plan), independent of whether coupon/payment-gateway boxes show.
					$("#urm-total_container").removeClass("urm-d-none");
					ur_membership_ajax_utils.calculate_total($(this));

					$(".urm_apply_coupon").show();
					if (urm_payment_type !== "free") {
						if (
							urmf_data.gateways_configured &&
							Object.keys(urmf_data.gateways_configured).length >
								0
						) {
							urm_hidden_pg_containers.removeClass("urm-d-none");

							urm_pg_inputs.each(function (key, item) {
								var current_gateway = $(item).val(),
									input_container = $(
										'label[for="ur-membership-' +
											current_gateway +
											'"]'
									);
								input_container.removeClass("urm-d-none");

								if (
									!(
										current_gateway in
										urmf_data.gateways_configured
									)
								) {
									input_container.addClass("urm-d-none");
								}
							});
						}
						urm_pg_inputs.each(function (key, item) {
							var current_gateway = $(item).val(),
								input_container = $(
									'label[for="ur-membership-' +
										current_gateway +
										'"]'
								);
							if (urmf_data.gateways_configured) {
							}
							if (
								!urm_payment_gateways.hasOwnProperty(
									current_gateway
								)
							) {
								input_container.addClass("urm-d-none");
							}
						});

						var visible_pg_labels = urm_pg_container_scoped
							.find('label[for^="ur-membership-"]')
							.not(".urm-d-none");
						var $pg_title_span = urm_pg_container_scoped.find(
							"span.ur_membership_input_label.ur-label.required, span.ur-upgrade-label.ur-label.required"
						);
						if (visible_pg_labels.length === 1) {
							var lone_input_id = visible_pg_labels.attr("for");
							urm_pg_container_scoped
								.find("input#" + lone_input_id)
								.prop("checked", true)
								.trigger("change");
							if (
								$pg_title_span.length &&
								!$pg_title_span.data("original-pg-label")
							) {
								$pg_title_span.data(
									"original-pg-label",
									$pg_title_span.text()
								);
							}
							$pg_title_span.text("Payment Gateway");
						} else {
							if ($pg_title_span.length) {
								$pg_title_span.text(
									$pg_title_span.data("original-pg-label") ||
										"Select Payment Gateway"
								);
							}
						}
					} else {
						stripe_container.addClass("urm-d-none");
					}
					ur_membership_currency_gateway_utils.evaluate($form_context);
				}
			);
			// membership input change trigger for page with membership id as params.
			var searchParams = new URLSearchParams(window.location.search),
				visible_memberships = $('input[name="urm_membership"]');

			$(document).on(
				"user_registration_membership_update_before_form_submit",
				function (e, data) {
					e.preventDefault();

					var has_error = false,
						selected_pg = "free",
						selected_plan = "";
					var pg_type = $(
							'input[name="urm_membership"]:checked'
						).data("urm-pg-type"),
						btn = $(this);
					//validation before request start
					selected_plan = $(
						'input[name="urm_membership"]:checked'
					).val();
					selected_pg =
						$('input[name="urm_payment_method"]:checked').val() ===
						undefined
							? selected_pg
							: $(
									'input[name="urm_payment_method"]:checked'
								).val();

					if (selected_pg && selected_pg !== "free") {
						var upgrade_currency =
								ur_membership_currency_gateway_utils.get_active_currency(),
							upgrade_unsupported_gateways =
								ur_membership_currency_gateway_utils.get_unsupported_gateways(
									upgrade_currency
								);

						if (
							$.inArray(selected_pg, upgrade_unsupported_gateways) !== -1
						) {
							$("#upgrade-membership-notice").text(
								ur_membership_currency_gateway_utils.build_message(
									upgrade_currency,
									upgrade_unsupported_gateways
								)
							);
							return;
						}
					}

					//validation end
					var action = searchParams.get("action"),
						current_membership_id = searchParams.get("current"),
						subscription_id = searchParams.get("subscription_id");

					if ($("#ur-membership-coupon").length > 0) {
						data.coupon = $("#ur-membership-coupon").val().trim();
					}

					if (action == "multiple") {
						ur_membership_ajax_utils.add_multiple_membership(
							data,
							selected_plan,
							selected_pg,
							btn,
							"multiple"
						);
					} else if (action == "upgrade") {
						if ($("#ur-membership-coupon").length > 0) {
							data.coupon = $("#ur-membership-coupon")
								.val()
								.trim();
						}

						if (!subscription_id && !current_membership_id) {
							subscription_id = $(
								".urm_membership_upgrade_data"
							).data("current-subscription-id");
							current_membership_id = $(
								".urm_membership_upgrade_data"
							).data("current-membership-id");
						}

						ur_membership_ajax_utils.upgrade_membership(
							data,
							current_membership_id,
							selected_plan,
							subscription_id,
							selected_pg,
							btn
						);
					} else if (action == "register") {
						ur_membership_ajax_utils.add_multiple_membership(
							data,
							selected_plan,
							selected_pg,
							btn,
							"register"
						);
					} else if (action === "renew") {
						var team_id = btn.data("team-id");

						ur_membership_ajax_utils.renew_membership(
							data,
							selected_pg,
							btn,
							current_membership_id,
							team_id
						);
					}
				}
			);

			if (searchParams.has("membership_id")) {
				$('input[name="urm_membership"]:checked').change();
			}
			if (
				visible_memberships !== undefined &&
				visible_memberships.length === 1
			) {
				$(visible_memberships[0]).prop("checked", true).change();
			}
			$(".close_notice").on(
				"click",
				ur_membership_frontend_utils.toggleNotice
			);

			$("#ur-membership-password").on("keyup change", function () {
				var $this = $(this),
					password = $this.val(),
					notice_div = $("#password-notice"),
					pass_regex = new RegExp(
						"^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[!@#$%^&*()_+}{\"':;?/>.<,]).*$"
					);
				ur_membership_frontend_utils.show_validation_error(
					notice_div,
					""
				);

				if (password.length < 8) {
					ur_membership_frontend_utils.show_validation_error(
						notice_div,
						urmf_data.labels
							.i18n_field_password_field_length_validation
					);
					return;
				}
				if (!pass_regex.test(password)) {
					ur_membership_frontend_utils.show_validation_error(
						notice_div,
						urmf_data.labels
							.i18n_field_password_field_regex_validation
					);
					return;
				}
				return true;
			});

			$("#ur-membership-confirm-password").on("keyup", function () {
				var $this = $(this),
					confirm_password = $this.val(),
					password = $("#ur-membership-password").val(),
					notice_div = $("#confirm-password-notice");
				ur_membership_frontend_utils.show_validation_error(
					notice_div,
					""
				);

				if (password.length === 0) {
					ur_membership_frontend_utils.show_validation_error(
						notice_div,
						urmf_data.labels.i18n_field_password_empty_validation
					);
				}
				if (confirm_password !== password) {
					ur_membership_frontend_utils.show_validation_error(
						notice_div,
						urmf_data.labels
							.i18n_field_confirm_password_field_validation
					);
					return;
				}
				return true;
			});

			//apply coupon event
			$(document).on("click", ".urm_apply_coupon", function () {
				ur_membership_ajax_utils.validate_coupon($(this));
			});
			//coupon clear input
			$(document).on("click", ".ur_clear_coupon", function () {
				ur_membership_ajax_utils.clear_applied_coupon();
			});
			// Clear applied coupon when the field is emptied (e.g. backspace), same as cancel (X).
			$(document).on("input", "#ur-membership-coupon", function () {
				if ($(this).val().trim() !== "") {
					return;
				}

				var selected_membership = $(
					'input[name="urm_membership"]:checked'
				);
				var hasAppliedDiscount =
					typeof selected_membership.attr(
						"data-ur-discount-amount"
					) !== "undefined" ||
					typeof selected_membership.data("ur-discount-amount") !==
						"undefined";

				if (!hasAppliedDiscount) {
					$("#coupon-validation-error")
						.text("")
						.removeClass("notice_blue notice_red");
					$(".urm_apply_coupon").show();
					return;
				}

				ur_membership_ajax_utils.clear_applied_coupon({
					skipEmptyInput: true
				});
			});
			//redirect to membership member registration form
			$(document).on(
				"click",
				".membership-selection-form .membership-signup-button",
				function (e) {
					e.preventDefault();

					if (urmf_data.isEditor) {
						return;
					}

					var $this = $(this),
						membership_id = $this
							.siblings('input[name="membership_id"]')
							.attr("value"),
						redirection_url = $this
							.siblings('input[name="redirection_url"]')
							.val(),
						thank_you_page_id = $this
							.siblings('input[name="thank_you_page_id"]')
							.val(),
						uuid = $this.siblings('input[name="urm_uuid"]').val(),
						action = $this.siblings('input[name="action"]').val();

					ur_membership_frontend_utils.clear_validation_error();

					if (
						$this
							.closest(".membership-selection-form")
							.find(".ur_membership_frontend_input_container")
							.hasClass("radio")
					) {
						var selected = $('input[name="membership_id"]:checked');

						if (selected.length > 0) {
							membership_id = selected.val();
							redirection_url = selected.data("redirect");
							thank_you_page_id = selected.data("thankyou");
							uuid = selected.data("urm-uuid");
							action = selected.data("action");
						} else {
							var error_div = $this
								.closest(".membership-selection-form")
								.find("#urm-listing-error");

							error_div
								.parent()
								.css("position", "static")
								.css("margin-bottom", "10px");

							ur_membership_frontend_utils.show_validation_error(
								error_div,
								urmf_data.labels.i18n_error +
									"! " +
									urmf_data.membership_selection_message
							);
							return;
						}
					}

					var concatenator =
						redirection_url.indexOf("?") === -1 ? "?" : "&";

					var url =
						redirection_url +
						concatenator +
						"membership_id=" +
						membership_id +
						"&action=" +
						action +
						"&thank_you=" +
						thank_you_page_id;

					if (action === "register") {
						url += "&uuid=" + uuid;
					}

					if ($(this).attr("target") === "_blank") {
						window.open(url, "_blank");
						return;
					}

					window.location.replace(url);
				}
			);

			//validate before submit
			$(document).on(
				"user_registration_frontend_validate_before_form_submit",
				function () {
					ur_membership_ajax_utils.validate_membership_form();
				}
			);

			$(document).on(
				"user_registration_frontend_validate_before_form_submit",
				function (e, $form) {
					var stripe_selected = false;
					$('input[name="urm_payment_method"]:checked').each(
						function () {
							if (
								$(this).val() === "stripe" &&
								$(this).is(":checked")
							) {
								stripe_selected = true;
							}
						}
					);
					// console.log('[URM Stripe Debug] stripe_selected:', stripe_selected, '| stripe_mode_validated:', stripe_mode_validated, '| elements.card:', !!elements.card, '| elements.stripe:', !!elements.stripe);
					if (!stripe_selected) return;

					if (stripe_mode_validated) return;

					var stripeEmpty = $(".ur-frontend-form").find(
						".stripe-input-container .StripeElement--empty"
					).length;
					// console.log('[URM Stripe Debug] elements.card:', !!elements.card, '| StripeElement--empty found:', stripeEmpty);
					if (
						!elements ||
						!elements.stripe ||
						!elements.card ||
						stripeEmpty
					) {
						// console.log('[URM Stripe Debug] Early return — skipping pre-validation');
						return;
					}

					// Prevent double submission
					if (stripe_validation_in_progress) return;
					stripe_validation_in_progress = true;

					var $submit_button =
						$form && $form.length
							? $form.find(".ur-submit-button")
							: $(".ur-submit-button");
					$submit_button.prop("disabled", true);
					$submit_button
						.find("span")
						.addClass("ur-front-spinner");

					// The core submit handler's `#stripe-errors:visible` guard is
					// what stops this first submit from racing straight to
					// registration while the card is still being validated (the
					// button's own disabled state only protects *later* clicks,
					// not this first synchronous pass). We still need that
					// element to exist, but the user already sees the button's
					// spinner, so this one is collapsed out of the layout
					// entirely (zero size, taken out of normal flow) rather
					// than just visually hidden — otherwise the `.user-
					// registration-error` class's own border/padding still
					// renders an empty box and reserves a gap. jQuery's
					// `:visible` only requires the element to generate a box
					// (any `getClientRects()` entry) — it doesn't need to have
					// non-zero size — so this still satisfies that guard.
					var $validating_marker = $(
						'<label id="stripe-errors" class="user-registration-error" role="alert" style="position:absolute;width:0;height:0;margin:0;padding:0;border:0;overflow:hidden;"></label>'
					);
					$membership_registration_form
						.find(".stripe-container")
						.closest(".ur_membership_frontend_input_container")
						.append($validating_marker);

					// show_stripe_error() reuses an existing #stripe-errors
					// element via .show(), which only toggles `display` and
					// would leave the marker's collapsing inline styles in
					// place — silently hiding a real error. Remove the marker
					// first so a genuine failure always renders a fresh,
					// fully-visible label.
					var show_real_stripe_error = function (message) {
						$membership_registration_form
							.find("#stripe-errors")
							.remove();
						stripe_settings.show_stripe_error(message);
					};

					var release_submit = function () {
						stripe_validation_in_progress = false;
						$submit_button.prop("disabled", false);
						$submit_button
							.find("span")
							.removeClass("ur-front-spinner");
					};

					elements.stripe
						.createPaymentMethod({
							type: "card",
							card: elements.card
						})
						.then(function (pmResult) {
							if (pmResult.error) {
								show_real_stripe_error(
									pmResult.error.message
								);
								release_submit();
								return;
							}

							$.post(
								urmf_data.ajax_url,
								{
									action: "user_registration_membership_validate_stripe_card_mode",
									security: urmf_data._nonce,
									payment_method_id: pmResult.paymentMethod.id
								},
								function (response) {
									if (response.success) {
										$membership_registration_form
											.find("#stripe-errors")
											.remove();
										validated_stripe_pm_id =
											pmResult.paymentMethod.id;
										stripe_mode_validated = true;
										stripe_validation_in_progress = false;
										if ($form && $form.length) {
											$form
												.find(".ur-submit-button")
												.prop("disabled", false);
											$form.submit();
										}
									} else {
										show_real_stripe_error(
											response.data &&
												response.data.message
												? response.data.message
												: urmf_data.labels
														.i18n_stripe_mode_error
										);
										release_submit();
									}
								}
							).fail(function () {
								release_submit();
							});
						})
						.catch(function () {
							release_submit();
						});
				}
			);
			$(document).on(
				"user_registration_frontend_before_form_submit",
				function (event, data, pointer, $error_message) {
					if (
						$(pointer).find("#ur-membership-registration").length >
						0
					) {
						var checkedGateway = $(pointer)
							.find('input[name="urm_payment_method"]:checked')
							.val();

						if (checkedGateway) {
							var activeCurrency =
									ur_membership_currency_gateway_utils.get_active_currency(),
								unsupportedGateways =
									ur_membership_currency_gateway_utils.get_unsupported_gateways(
										activeCurrency
									);

							if (
								$.inArray(checkedGateway, unsupportedGateways) !== -1
							) {
								$error_message.message =
									ur_membership_currency_gateway_utils.build_message(
										activeCurrency,
										unsupportedGateways
									);
								return;
							}
						}

						var membersData = ur_membership_ajax_utils.prepare_members_data();
						if (validated_stripe_pm_id) {
							membersData.payment_method_id = validated_stripe_pm_id;
						}
						data["members_data"] = JSON.stringify(membersData);
						data["is_membership_active"] = membersData.membership;
						data["membership_type"] = membersData.membership;
						if (typeof ur_authorize_net !== "undefined") {
							data["ur_authorize_net"] = ur_authorize_net;
						}
					}
					if (validated_stripe_pm_id) {
						data["urm_stripe_pm_id"] = validated_stripe_pm_id;
					}
				}
			);
			$(document).on(
				"user_registration_frontend_before_ajax_complete_success_message",
				function (event, ajax_response, ajaxFlag, form) {
					var flag = true,
						response = JSON.parse(ajax_response.responseText),
						required_data = {
							data: response.data,
							form_id: $(form).data("form-id")
						};

					if (
						typeof response.data.registration_type !==
							"undefined" &&
						response.data.registration_type === "membership"
					) {
						var hidden_fields_raw = $(form).find("#urcl_hide_fields").val() || "[]";
						var hidden_fields = [];
						try { hidden_fields = JSON.parse(hidden_fields_raw); } catch (e) {}
						var membership_field_name = $('input[name="urm_membership"]').first().attr('data-name');
						var membership_is_hidden = membership_field_name && hidden_fields.indexOf(membership_field_name) !== -1;

						if (!membership_is_hidden) {
							flag = false;
							var membersData = ur_membership_ajax_utils.prepare_members_data();
							membersData.username = response.data.username;
							ur_membership_ajax_utils.handle_response(response, membersData, required_data);
						} else {
							// Membership field was hidden by conditional logic.
							// Strip registration_type so the main JS shows the normal success message and redirect.
							delete response.data.registration_type;
							ajax_response.responseText = JSON.stringify(response);
						}
					}
					ajaxFlag["status"] = flag;
				}
			);

			$(document).on(
				"change",
				'.membership-upgrade-container input[name="urm_payment_method"]',
				function () {
					var selected_method = $(this).val(),
						stripe_container = $(".stripe-container"),
						stripe_error_container = $("#stripe-errors"),
						upgrade_error_container = $(
							"#upgrade-membership-notice"
						);

					var authorize_container = $(".authorize-net-container");
					var authorize_error_container = $("#authorize-errors");

					upgrade_error_container.text("");
					stripe_container.addClass("urm-d-none");
					stripe_error_container.remove();

					authorize_container.addClass("urm-d-none");
					authorize_error_container.remove();
					authorize_container
						.find(
							"#user_registration_authorize_net_card_number, #user_registration_authorize_net_expiration, #user_registration_authorize_net_card_code"
						)
						.val("");
					authorize_container.find(".ur-authorize-net-errors").remove();
					authorize_container
						.find("#authorizenet_gateway_field")
						.removeClass("ur-authorize-net-error");
					authorize_container
						.find(".ur-anet-sub-field")
						.removeClass("ur-input-border-red");

					var stripeAlreadyReady =
						selected_method === "stripe" &&
						elements &&
						elements.card;
					if (!stripeAlreadyReady) {
						elements = {};
					}
					if (selected_method === "stripe") {
						if (urmf_data.stripe_publishable_key.length == 0) {
							ur_membership_frontend_utils.show_failure_message(
								urmf_data.labels
									.i18n_incomplete_stripe_setup_error
							);
							return;
						}
						stripe_container.removeClass("urm-d-none");
						stripe_settings.init(true);
					}
					if (selected_method === "authorize") {
						authorize_container.removeClass("urm-d-none");
					}

					ur_membership_currency_gateway_utils.evaluate(
						$(this).closest(".membership-upgrade-container")
					);
				}
			);
			//cancel membership button
			$(document).on("click", ".cancel-membership-button", function (e) {
				e.preventDefault();
				var $this = $(this),
					membership_title = $("#membership-title").text();

				if ($this.find(".urm-spinner").length) {
					return;
				}
				ur_membership_frontend_utils.append_spinner($this);

				Swal.fire({
					icon: "warning",
					title:
						urmf_data.labels.i18n_cancel_membership_text +
						" " +
						membership_title.trim(),
					text: urmf_data.labels.i18n_cancel_membership_subtitle,
					customClass:
						"user-registration-upgrade-membership-swal2-container",
					showConfirmButton: true,
					showCancelButton: true
				}).then(function (result) {
					if (result.isConfirmed) {
						$.ajax({
							url: urmf_data.ajax_url,
							type: "POST",
							data: {
								action: "user_registration_membership_cancel_subscription",
								security: urmf_data._nonce,
								subscription_id: $this.data("id")
							},
							success: function (response) {
								if (response.success) {
									location.reload();
								} else {
									if (
										$(".user-registration-membership-notice__container").length === 0
									) {
										$("body").append(
											'<div class="user-registration-membership-notice__container urm-notice-error" style="display:none;"><span class="user-registration-membership-notice__message"></span><span class="user-registration-membership__close_notice">&times;</span></div>'
										);
									}
									$(document).trigger("urm_show_action_message", {
										message: response.data.message,
										type: "error"
									});
									var $notice = $(".user-registration-membership-notice__container");
									$notice.addClass("urm-notice-error");
									$notice
										.find(".user-registration-membership__close_notice")
										.off("click.urmcancel")
										.on("click.urmcancel", function () {
											$notice.fadeOut(200);
										});
									clearTimeout(window.__urmCancelNoticeTimer);
									window.__urmCancelNoticeTimer = setTimeout(function () {
										$notice.fadeOut(300);
									}, 6000);
								}
							},
							complete: function () {
								ur_membership_frontend_utils.remove_spinner($this);
							}
						});
					} else {
						ur_membership_frontend_utils.remove_spinner($this);
					}
				});
			});
			$(document).on(
				"click",
				".reactivate-membership-button",
				function (e) {
					e.preventDefault();

					var $this = $(this),
						error_div = $("#membership-error-div"),
						button_text = $this.text(),
						membership_title = $("#membership-title").text();
					$.ajax({
						url: urmf_data.ajax_url,
						type: "POST",
						data: {
							action: "user_registration_membership_reactivate_membership",
							security: urmf_data._nonce,
							subscription_id: $this.data("id")
						},
						beforeSend: function () {
							ur_membership_frontend_utils.append_spinner($this);
							ur_membership_frontend_utils.toggleSaveButtons(true, $this);
						},
						success: function (response) {
							if (!response.success) {
								if (
									$(
										".user-registration-page .notice-container"
									).length === 0
								) {
									$(
										".user-registration-membership-notice__container"
									).remove();
									// Adds the toast container on the top of page.
									$(document)
										.find(".user-registration-page")
										.prepend(
											'<div class="user-registration-membership-notice__container"><div class="ur-toaster urm-error user-registration-membership-notice__red"><span class="user-registration-membership-notice__message"></span><span class="user-registration-membership__close_notice">&times;</span></div></div>'
										);
								}
								$(document).trigger("urm_show_action_message", {
									message: response.data.message,
									type: response.success ? "success" : "error"
								});
							} else {
								location.reload();
							}
						},
						complete: function () {
							ur_membership_frontend_utils.remove_spinner($this);
							ur_membership_frontend_utils.toggleSaveButtons(false, $this);
						}
					});
				}
			);

			$("#membership-error-div .cancel-notice").on("click", function () {
				$(this).siblings("span").text("");
				$(this).closest("#membership-error-div").hide();
			});
			//disable submit button if empty membership field
			if ($(".field-membership").length) {
				$(".field-membership").each(function (key, item) {
					if ($(item).find(".no-membership")) {
						var form_id = $(item)
							.find(".no-membership")
							.attr("data-form-id");
						$("#user-registration-form-" + form_id)
							.find(".ur-submit-button")
							.prop("disabled", true);
					}
				});
			}

			$(document).on("click", ".view-bank-data", function (e) {
				e.preventDefault();
				e.stopPropagation();

				jQuery(".user-registration-help-tip.tooltipstered").tooltipster(
					"close"
				);

				var html =
					jQuery(this)
						.closest(".tooltipster-box")
						.find(".upgrade-info")
						.html() ||
					jQuery(this).siblings(".upgrade-info").html();

				Swal.fire({
					title: urmf_data.labels.i18n_bank_details_title,
					html: html,
					customClass:
						"user-registration-upgrade-membership-swal2-container",
					showCancelButton: false,
					showConfirmButton: false
				});
			});

			$(document).on(
				"change",
				"#ur-local-currency-switch-currency",
				function (e) {
					e.preventDefault();

					var $el = $(this),
						currency = $el.val(),
						urmMembership = $('input[name="urm_membership"]'),
						currencySymbols =
							ur_membership_frontend_localized_data.local_currencies_symbol,
						symbol = register_events.decodeHtmlEntity(
							currencySymbols[currency] || ""
						);

					urmMembership.each(function () {
						var $membershipRadio = $(this),
							localCurrencyDetails =
								$membershipRadio.data(
									"urm-local-currency-details"
								) || {},
							calculatedAmount =
								parseFloat(
									$membershipRadio.data(
										"urm-pg-calculated-amount"
									)
								) || 0,
							discountAmount =
								parseFloat(
									$membershipRadio.data("ur-discount-amount")
								) || 0,
							total = discountAmount
								? calculatedAmount - discountAmount
								: calculatedAmount,
							membershipType =
								$membershipRadio.data("urm-pg-type");

						if (membershipType !== "free") {
							if (
								localCurrencyDetails[currency] &&
								localCurrencyDetails[currency].hasOwnProperty(
									"ID"
								)
							) {
								$el.data(
									"urm-zone-id",
									localCurrencyDetails[currency].ID
								).attr(
									"data-urm-zone-id",
									localCurrencyDetails[currency].ID
								);
							}
							var $span = $membershipRadio.siblings(
								".ur-membership-period-span"
							);
							var $periodText = $span.find(
								".ur-membership-period-text"
							);

							var oldText = $periodText.length
								? $periodText.text()
								: $span.text();
							var durationPart = "";
							var everyIndex = oldText
								.toLowerCase()
								.indexOf(" every ");

							if (everyIndex !== -1) {
								durationPart = oldText
									.substring(everyIndex)
									.trim();
							} else {
								// Legacy slash format: "$200.00 / 2 Weeks".
								var parts = oldText.split("/");
								durationPart = parts[1]
									? "/ " + parts[1].trim()
									: "";
							}

							if (localCurrencyDetails[currency]) {
								var newCalculatedValue =
									total *
									parseFloat(
										localCurrencyDetails[currency].rate
									);
								newCalculatedValue =
									newCalculatedValue.toFixed(2);

								if (
									"manual" ==
									localCurrencyDetails[currency]
										.pricing_method
								) {
									// "rate" is a flat local price here, not a multiplier — scale by the discount ratio.
									var fullManualPrice = parseFloat(
										localCurrencyDetails[currency].rate
									);
									var discountRatio =
										calculatedAmount > 0
											? total / calculatedAmount
											: 1;

									newCalculatedValue = (
										fullManualPrice * discountRatio
									).toFixed(2);
								}

								if (urmf_data.curreny_pos === "left") {
									$periodText.length
										? $periodText.text(
												symbol +
													newCalculatedValue +
													" " +
													durationPart
											)
										: $span.text(
												symbol +
													newCalculatedValue +
													" " +
													durationPart
											);
								} else {
									$periodText.length
										? $periodText.text(
												newCalculatedValue +
													symbol +
													" " +
													durationPart
											)
										: $span.text(
												newCalculatedValue +
													symbol +
													" " +
													durationPart
											);
								}

								$membershipRadio.data(
									"urm-converted-amount",
									newCalculatedValue
								);
								$membershipRadio
									.data("local-currency", currency)
									.attr("data-local-currency", currency);
								if ($membershipRadio.is(":checked")) {
									ur_membership_ajax_utils.calculate_total(
										$membershipRadio
									);
								}
							} else {
								$membershipRadio.data(
									"urm-converted-amount",
									0
								);
								total = total.toFixed(2);
								if (urmf_data.curreny_pos === "left") {
									$periodText.length
										? $periodText.text(
												urmf_data.currency_symbol +
													total +
													" " +
													durationPart
											)
										: $span.text(
												urmf_data.currency_symbol +
													total +
													" " +
													durationPart
											);
									if ($membershipRadio.is(":checked")) {
										ur_membership_ajax_utils.calculate_total(
											$membershipRadio
										);
									}
								} else {
									$periodText.length
										? $periodText.text(
												total +
													urmf_data.currency_symbol +
													" " +
													durationPart
											)
										: $span.text(
												total +
													urmf_data.currency_symbol +
													" " +
													durationPart
											);
									if ($membershipRadio.is(":checked")) {
										ur_membership_ajax_utils.calculate_total(
											$membershipRadio
										);
									}
								}
							}
						}
					});

					ur_membership_currency_gateway_utils.evaluate();
				}
			);

			$(document).on(
				"change",
				".ur-field-address-country, .ur-field-address-state",
				function (e) {
					e.stopPropagation();
					e.preventDefault();
					var membership_input = $(
						'input[name="urm_membership"]:checked'
					);
					ur_membership_ajax_utils.calculate_total(membership_input);
				}
			);

			$(".ur-label.ur-has-team-pricing").on("click", function (e) {
				var label = $(this);
				var membership_id = $(this).data("membership-id");
				var teamContainer = $(
					"#urm-team-pricing-container-" + membership_id
				);
				var radio = teamContainer.find('input[type="radio"]');

				$(this).hide();
				teamContainer.show();
				radio.prop("checked", true).trigger("change");

				label.hide();
				teamContainer.show();

				// Hide all other team containers and show their regular labels
				$(".urm-team-pricing-container").not(teamContainer).hide();
				$(".ur-label.ur-has-team-pricing").not(label).show();

				e.preventDefault();
			});

			$(".ur-label.ur-normal-pricing").on("click", function () {
				var label = $(this);
				var radio = label.find('input[type="radio"]');

				radio.prop("checked", true).trigger("change");

				// Hide all team containers and show their regular labels
				$(".urm-team-pricing-container").hide();
				$(".ur-label.ur-has-team-pricing").show();
			});

			$(".ur-team-pricing-label").on("click", function (e) {
				var label = $(this);
				var radio = label.find('input[type="radio"]');

				radio.prop("checked", true).trigger("change");

				e.preventDefault();
			});

			$(".ur-membership-price").on("click", function () {
				$(this).addClass("ur-membership-price-selected");
				var container = $(this).closest(".urm-team-pricing-card");
				var radio = container.find('input[type="radio"]');
				container
					.find(".urm-team-pricing-tier")
					.removeClass("selected");
				container.find(".ur-team-tier-seats-wrapper").hide();
				radio.data("urm-pg-calculated-amount", $(this).data("price"));
				ur_membership_ajax_utils.calculate_total(radio);
				$(
					'.ur-membership-payment-gateway-lists label[for="ur-membership-mollie"]'
				).show();
				$(
					'.ur-membership-payment-gateway-lists label[for="ur-membership-authorize"]'
				).show();
				$(".ur-team-tier-seats-tier").hide();
			});

			function calculatePrice(tier) {
				var radio = tier
					.closest(".urm-team-pricing-card")
					.find('input[name="urm_membership"]');

				var seatInput = tier.find('input[name="no_of_seats"]');
				var seats = parseInt(seatInput.val(), 10) || 0;

				var seatModel = tier.data("seat-model");
				var pricingModel = tier.data("pricing-model");

				var amount = 0;

				if (seatModel === "fixed") {
					amount = tier.data("fixed-price") || 0;
				}

				if (seatModel === "variable" && pricingModel === "per_seat") {
					var perSeat = tier.data("per-seat-price") || 0;
					amount = seats * perSeat;
				}

				if (seatModel === "variable" && pricingModel === "tier") {
					var selectedTierRadio = tier.find(
						".ur-tier-radio-input:checked"
					);
					if (!selectedTierRadio.length) return;

					var tierPrice =
						parseFloat(selectedTierRadio.data("tier-price")) || 0;
					amount = seats * tierPrice;
				}

				radio.data("urm-pg-calculated-amount", amount);
				ur_membership_ajax_utils.calculate_total(radio);
			}

			$(document).on("click", ".urm-team-pricing-tier", function (e) {
				if (
					$(e.target).is(".ur-tier-radio-input") ||
					$(e.target).is("input[name='no_of_seats']")
				)
					return;

				var tier = $(this);
				var container = tier.closest(".urm-team-pricing-card");
				var singlePrice = container.find(
					".ur-membership-price-selected"
				);
				$(".urm-team-pricing-tier").removeClass("selected");
				$(".ur-team-tier-seats-wrapper").hide();
				$(".ur-team-tier-seats-tier").hide();
				$(
					'.ur-membership-payment-gateway-lists label[for="ur-membership-mollie"]'
				).hide();
				$(
					'.ur-membership-payment-gateway-lists label[for="ur-membership-authorize"]'
				).hide();

				tier.addClass("selected");
				singlePrice.removeClass("ur-membership-price-selected");
				$(".ur-tier-radio-input").prop("checked", false);
				tier.find(".ur-tier-radio-input").prop("disabled", false);
				$(".ur-tier-radio-input")
					.not(tier.find(".ur-tier-radio-input"))
					.prop("disabled", true);

				var seatModel = tier.data("seat-model");
				var pricingModel = tier.data("pricing-model");
				var seatInput = tier.find('input[name="no_of_seats"]');

				$('input[name="no_of_seats"]')
					.not(seatInput)
					.each(function () {
						var defaultValue =
							$(this).attr("min") ||
							$(this).attr("placeholder") ||
							"";
						$(this).val(defaultValue);
						$(this).prop("disabled", true);
						$(this).removeClass("ur-input-border-red");
						$(this).removeAttr("aria-invalid");
					});
				// validation fix
				$("#no_of_seats-error").remove();

				if (seatModel === "variable" && pricingModel === "per_seat") {
					tier.find(".ur-team-tier-seats-wrapper").show();
					seatInput
						.prop("disabled", false)
						.val(seatInput.attr("min") || 1);
					calculatePrice(tier);
				}

				if (seatModel === "fixed") {
					calculatePrice(tier);
				}

				if (seatModel === "variable" && pricingModel === "tier") {
					var tier_range_wrapper = tier.find(
						".ur-team-tier-seats-tier"
					);
					tier_range_wrapper.show();
					tier.find(".ur-team-tier-seats-wrapper").hide();
					seatInput.prop("disabled", true);
				}
			});

			$(document).on(
				"input",
				'.urm-team-pricing-tier.selected input[name="no_of_seats"]',
				function () {
					calculatePrice($(this).closest(".urm-team-pricing-tier"));
				}
			);

			$(document).on("change", ".ur-tier-radio-input", function (e) {
				var radio = $(this);
				var tier = radio.closest(".urm-team-pricing-tier");
				if (!tier.hasClass("selected")) {
					e.preventDefault();
					e.stopImmediatePropagation();
					radio.prop("checked", false);
					return;
				}

				var seatInput = tier.find('input[name="no_of_seats"]');
				var from = parseInt(radio.data("tier-from"), 10);
				var to = parseInt(radio.data("tier-to"), 10);

				tier.attr("data-selected-tier", radio.val());
				$(".ur-team-tier-seats-wrapper").hide();
				tier.find(".ur-team-tier-seats-wrapper").show();
				seatInput
					.attr({ min: from, max: to })
					.val(from)
					.prop("disabled", false);
				seatInput.trigger("input");
				$('input[name="no_of_seats"]')
					.not(seatInput)
					.prop("disabled", true);
			});
		},
		validateSwitchCurrency: function (paymentMethod) {
			var $select = $("#ur-local-currency-switch-currency");

			if (!urmf_data.supported_currencies.hasOwnProperty(paymentMethod)) {
				$select.find("option").show();
				$select
					.val($select.find("option:first").val())
					.trigger("change");
				return;
			}

			var supportedCurrencies =
				urmf_data.supported_currencies[paymentMethod];

			$select.find("option").each(function () {
				var currency = $(this).val();

				if (supportedCurrencies.indexOf(currency) === -1) {
					$(this).hide();
				} else {
					$(this).show();
				}
			});

			var firstVisible = $select.find("option:visible:first").val();
			$select.val(firstVisible).trigger("change");
		},
		decodeHtmlEntity: function (str) {
			var txt = document.createElement("textarea");
			txt.innerHTML = str;
			return txt.value;
		}
	};
	register_events.init();
	$(document).ready(function () {
		$("#ur-local-currency-switch-currency").trigger("change");
	});

	var dialog = $("#URCR-Restriction-Modal");

	if (dialog.length && typeof MutationObserver !== "undefined") {
		if (
			$(document).find(
				"#URCR-Restriction-Modal .user-registration.ur-frontend-form > form"
			)
		) {
			var observer = new MutationObserver(function (mutations) {
				for (var i = 0; i < mutations.length; i++) {
					if (mutations[i].attributeName === "open") {
						if (dialog.is("[open]")) {
							register_events.init();
						}
					}
				}
			});

			observer.observe(dialog[0], {
				attributes: true
			});
		}
	}
})(jQuery, window.ur_membership_frontend_localized_data);
