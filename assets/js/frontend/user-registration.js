/* global  user_registration_params, ur_frontend_params_with_form_id */
(function ($) {
	var user_registration_form_init = function () {
		var ursL10n = user_registration_params.ursL10n;

		var user_registration_frontend_utils = {
			/**
			 * Function to show success message.
			 *
			 * @since 4.2.1
			 */
			show_success_message: function (message) {
				$(
					".user-registration-membership-notice__container .user-registration-membership-notice__red"
				)
					.removeClass("user-registration-membership-notice__red")
					.addClass("user-registration-membership-notice__blue");
				$(".user-registration-membership-notice__message").text(
					message
				);
				$(".user-registration-membership-notice__container").css(
					"display",
					"block"
				);
				//attach near my account title.
				if (
					$(document).find(".user-registration-MyAccount").length ===
					1
				) {
					$(
						".user-registration-membership-notice__container .ur-toaster"
					);
				}
				// this.toggleNotice();
				this.ur_remove_cookie("urm_toast_content");
				this.ur_remove_cookie("urm_toast_success_message");
			},
			show_error_message: function (message) {
				$(".user-registration-membership-notice__container")
					.removeClass("user-registration-membership-notice__blue")
					.addClass("user-registration-membership-notice__red");
				$(".user-registration-membership-notice__message").text(
					message
				);
				$(".user-registration-membership-notice__container").css(
					"display",
					"block"
				);

				//attach near my account title.
				if (
					$(document).find(".user-registration-MyAccount").length ===
					1
				) {
					$(
						".user-registration-membership-notice__container .ur-toaster "
					);
				}
				// this.toggleNotice();
				this.ur_remove_cookie("urm_toast_content");
				this.ur_remove_cookie("urm_toast_success_message");
			},

			/**
			 * Removes the notice after some time.
			 *
			 * @since 4.2.1
			 */
			toggleNotice: function () {
				var noticeContainer = $(
					".user-registration-membership-notice__container"
				);
				setTimeout(function () {
					noticeContainer.fadeOut(4000);
				}, 4000);
			},

			/**
			 * Retrieves the cookie values set.
			 *
			 * @since 4.2.1
			 */
			ur_get_cookie: function (cookie_key) {
				var matches = document.cookie.match(
					new RegExp(
						"(?:^|; )" +
							cookie_key.replace(
								/([\.$?*|{}\(\)\[\]\\\/\+^])/g,
								"\\$1"
							) +
							"=([^;]*)"
					)
				);
				return matches ? decodeURIComponent(matches[1]) : undefined;
			},

			/**
			 * Deletes the cookie values.
			 *
			 * @since 4.2.1
			 */
			ur_remove_cookie: function (cookie_key) {
				document.cookie = cookie_key + "=; Max-Age=-99999999; path=/" + (window.location.protocol === 'https:' ? '; Secure' : '') + "; SameSite=Strict";
			}
		};

		$.fn.ur_form_submission = function () {
			// traverse all nodes
			return this.each(function () {
				// express a single node as a jQuery object
				var $this = $(this);
				var available_field = [];
				var required_fields =
					user_registration_params.form_required_fields;
				var form = {
					init: function () {},
					get_form_data: function (form_id) {
						if (
							form_id ===
								$this.closest(".ur-frontend-form").attr("id") ||
							$(".ur-frontend-form")
								.find("form.edit-profile")
								.hasClass("user-registration-EditProfileForm")
						) {
							var this_instance = this;
							var form_data = [];
							var frontend_field = form.separate_form_handler("");
							var repeater_field_data = {};

							$this
								.closest("form")
								.find(".ur-repeater-row")
								.each(function () {
									var fieldName = $(this)
										.closest(".ur-repeater-row")
										.data("repeater-field-name");
									var rowName =
										"row_" +
										$(this)
											.closest(".ur-repeater-row")
											.data("repeater-row");

									if (
										$(this).closest(
											".user-registration-EditProfileForm"
										).length > 0
									) {
										fieldName =
											"user_registration_" + fieldName;
									}

									if (!repeater_field_data[fieldName]) {
										repeater_field_data[fieldName] = {
											field_name: fieldName,
											field_type: "repeater",
											value: {},
											label: $(this)
												.closest(".ur-repeater-row")
												.find(".ur-repeater-label")
												.find(".ur-label")
												.text(),
											extra_params: {
												field_key: "repeater",
												label: $(this)
													.closest(".ur-repeater-row")
													.find(".ur-repeater-label")
													.find(".ur-label")
													.text()
											}
										};
									}

									if (
										!repeater_field_data[fieldName][
											"value"
										][rowName]
									) {
										repeater_field_data[fieldName]["value"][
											rowName
										] = [];
									}
								});

							var multi_value_field = new Array();
							$.each(frontend_field, function () {
								var field_name = $(this).attr("name");
								var field_type = $(this).attr("type");
								var single_field = form.separate_form_handler(
									'[name="' + field_name + '"]'
								);

								var selection_fields_array = ["radio"];
								var fieldName = $(this)
									.closest(".ur-repeater-row")
									.data("repeater-field-name");

								if (
									$(this).closest(
										".user-registration-EditProfileForm"
									).length > 0
								) {
									fieldName =
										"user_registration_" + fieldName;
								}

								var rowName =
									"row_" +
									$(this)
										.closest(".ur-repeater-row")
										.data("repeater-row");

								if (
									(single_field.length < 2 ||
										single_field.closest(".ur-repeater-row")
											.length > 0) &&
									$.inArray(
										field_type,
										selection_fields_array
									) < 0
								) {
									var single_data =
										this_instance.get_fieldwise_data(
											$(this)
										);

									var invite_code =
										document.querySelector("#invite_code");

									if (
										"invite_code" === single_data.field_name
									) {
										if (
											"none" !== invite_code.style.display
										) {
											form_data.push(single_data);
										}
									} else {
										if (
											$(this).closest(".ur-repeater-row")
												.length > 0
										) {
											if (
												$(this)
													.closest(".form-row")
													.find(
														"*[name='" +
															$(this).attr(
																"name"
															) +
															"']"
													).length < 2 ||
												"range" ===
													$(this).attr("type") ||
												$(this).hasClass(
													"ur-smart-phone-field"
												)
											) {
												repeater_field_data[fieldName][
													"value"
												][rowName].push(single_data);
											} else {
												if (
													multi_value_field.indexOf(
														single_data.field_name +
															"[]"
													) === -1
												) {
													multi_value_field.push(
														single_data.field_name +
															"[]"
													);
												}
											}
										} else {
											form_data.push(single_data);
										}
									}
								} else {
									if (
										$.inArray(
											field_name,
											multi_value_field
										) < 0
									) {
										multi_value_field.push(field_name);
									}
								}
							});

							for (
								var multi_start = 0;
								multi_start < multi_value_field.length;
								multi_start++
							) {
								var field = form.separate_form_handler(
									'[name="' +
										multi_value_field[multi_start] +
										'"]'
								);
								var node_type = field
									.get(0)
									.tagName.toLowerCase();

								var field_type =
									"undefined" !== field.eq(0).attr("type")
										? field.eq(0).attr("type")
										: "null";

								var field_value = new Array();

								var repeater_field_value = {};
								$.each(field, function () {
									var this_field = $(this);

									var this_field_value = "";

									switch (
										this_field.get(0).tagName.toLowerCase()
									) {
										case "input":
											switch (field_type) {
												case "checkbox":
												case "radio":
													this_field_value =
														this_field.prop(
															"checked"
														)
															? this_field.val()
															: "";
													break;
												default:
													this_field_value =
														this_field.val();
											}
											break;
										case "select":
											this_field_value = this_field.val();
											break;
										case "textarea":
											this_field_value = this_field.val();
											break;
										default:
									}

									if (this_field_value !== "") {
										if (
											this_field.closest(
												".ur-repeater-row"
											).length > 0
										) {
											if (
												this_field.closest(
													".field-radio"
												).length > 0
											) {
												repeater_field_value[
													this_field.attr("data-id")
												] = this_field_value;
											} else {
												if (
													"undefined" ===
													typeof repeater_field_value[
														this_field.attr(
															"data-id"
														)
													]
												) {
													repeater_field_value[
														this_field.attr(
															"data-id"
														)
													] = new Array();
												}
												repeater_field_value[
													this_field.attr("data-id")
												].push(this_field_value);
											}
										} else {
											field_value.push(this_field_value);
										}
									}
								});

								if (field_type == "checkbox") {
									if (
										"" !==
										user_registration_params.is_payment_compatible
									) {
										if (
											field.eq(0).attr("data-field") ==
											"multiple_choice"
										) {
											$(document).trigger(
												"user_registration_frontend_multiple_choice_data_filter",
												[field_value, field]
											);
											field_value = field
												.closest(
													".field-multiple_choice"
												)
												.data("payment-value");

											var field_value_json =
												JSON.stringify(field_value);
										} else {
											var field_value_json =
												JSON.stringify(field_value);
										}
									} else {
										if (
											field.eq(0).attr("data-field") ==
											"multiple_choice"
										) {
											var multi_choice = field_value;
											var field_value_json = 0;
											for (
												var i = 0;
												i < multi_choice.length;
												i++
											) {
												field_value_json +=
													multi_choice[i] << 0;
											}
										} else {
											var field_value_json =
												JSON.stringify(field_value);
										}
									}
								} else if (field_type == "radio") {
									if (
										"" !==
										user_registration_params.is_payment_compatible
									) {
										if (
											field.eq(0).attr("data-field") ==
											"subscription_plan"
										) {
											$(document).trigger(
												"user_registration_frontend_subscription_plan_data_filter",
												[field_value, field]
											);
											selectedSubscriptionPlan = field
												.closest(
													".field-subscription_plan"
												)
												.find(
													'input[name="subscription_plan[]"]:checked'
												);

											if (
												selectedSubscriptionPlan.length >
												0
											) {
												// Get the data attribute value
												var dataValue =
													selectedSubscriptionPlan.data(
														"value"
													);
												var field_value_json =
													JSON.stringify(
														dataValue +
															":" +
															selectedSubscriptionPlan.val()
													);
											}
										} else {
											var field_value_json =
												field_value[0];
										}
									} else {
										var field_value_json = field_value[0];
									}
								} else {
									var field_value_json = field.val();
								}

								var single_form_field_name =
									multi_value_field[multi_start];
								single_form_field_name =
									single_form_field_name.replace("[]", "");

								if (
									single_form_field_name === "urm_membership"
								) {
									single_form_field_name = field
										.eq(0)
										.attr("data-name");
								}
								var field_data = {
									value: field_value_json,
									field_type: field_type,
									label: field.eq(0).attr("data-label"),
									field_name: single_form_field_name
								};

								if (
									Object.keys(repeater_field_value).length > 0
								) {
									var field_detail = new Array();

									$.each(
										repeater_field_value,
										function (key, value) {
											key =
												$("[name='" + key + "']")
													.length < 1 &&
												key.indexOf("[]") === -1
													? key + "[]"
													: key;
											var row_id = $(
												'[name="' + key + '"]'
											)
												.closest(".ur-repeater-row")
												.data("repeater-row");
											var repeater_value = Object.assign(
												{},
												field_data
											);

											repeater_value.value =
												"string" === typeof value
													? value
													: JSON.stringify(value);

											repeater_value.field_name =
												single_form_field_name.slice(
													0,
													-2
												);

											var current_repeater_field_name =
												"undefined" ===
												typeof repeater_field_data[
													$("[name='" + key + "']")
														.closest(
															".ur-repeater-row"
														)
														.data(
															"repeater-field-name"
														)
												]
													? "user_registration_" +
														$(
															"[name='" +
																key +
																"']"
														)
															.closest(
																".ur-repeater-row"
															)
															.data(
																"repeater-field-name"
															)
													: $("[name='" + key + "']")
															.closest(
																".ur-repeater-row"
															)
															.data(
																"repeater-field-name"
															);
											repeater_field_data[
												current_repeater_field_name
											]["value"]["row_" + row_id].push(
												repeater_value
											);
										}
									);
								} else {
									form_data.push(field_data);
								}
							}

							Object.keys(repeater_field_data).forEach(
								function (field_key) {
									if (
										$("input[name='" + field_key + "'")
											.length > 0
									) {
										$("input[name='" + field_key + "'").val(
											JSON.stringify(
												repeater_field_data[field_key]
											)
										);
									}
								}
							);

							if (Object.keys(repeater_field_data).length > 0) {
								$.merge(
									form_data,
									Object.values(repeater_field_data)
								);
							}
							$(document).trigger(
								"user_registration_frontend_form_data_filter",
								[form_data]
							);
							return form_data;
						}
					},
					get_fieldwise_data: function (field) {
						var formwise_data = {};

						var node_type = field.get(0).tagName.toLowerCase();
						var field_name =
							"undefined" !== field.attr("name")
								? field.attr("name")
								: "null";

						var phone_id = [];
						if (
							field.attr("name") !== undefined &&
							field.attr("name") !== ""
						) {
							formwise_data.field_name = field.attr("name");
							formwise_data.field_name =
								formwise_data.field_name.replace("[]", "");

							if (
								$(field).closest(".ur-repeater-row").length > 0
							) {
								if (
									$(field).closest(".field-multi_select2")
										.length > 0
								) {
									formwise_data.field_name =
										formwise_data.field_name.slice(0, -2);
								}

								if (
									$(field).closest(".field-file").length > 0
								) {
									formwise_data.field_name = $(field)
										.closest(".field-file")
										.attr("data-ref-id");
								}
							}
						} else {
							formwise_data.field_name = "";
						}

						$(".field-phone, .field-billing_phone").each(
							function () {
								var phone_field_id = $(this)
									.find(".form-row")
									.attr("id");
								// Check if smart phone field is enabled.
								if (
									$(this)
										.find(".form-row")
										.find("#" + phone_field_id)
										.hasClass("ur-smart-phone-field")
								) {
									phone_id.push(
										$(this).find(".form-row").attr("id")
									);
								}
							}
						);
						var field_type =
							"undefined" !== field.attr("type")
								? field.attr("type")
								: "null";

						var textarea_type = field
							.get(0)
							.className.split(" ")[0];

						formwise_data.value = "";

						switch (node_type) {
							case "input":
								var checked_value = new Array();
								switch (field_type) {
									case "checkbox":
										if (
											!field.closest(
												".field-privacy_policy"
											).length > 0
										) {
											if (field.prop("checked")) {
												checked_value.push(field.val());
												formwise_data.value =
													JSON.stringify(
														checked_value
													);
												if (
													"separate_shipping" ===
													field.attr("data-id")
												) {
													formwise_data.value =
														field.val();
												}
											} else {
												formwise_data.value = "";
											}
										} else {
											formwise_data.value = field.prop(
												"checked"
											)
												? field.val()
												: "";

											privacy_field_name = field
												.closest(
													".field-privacy_policy"
												)
												.data("ref-id");

											if (
												"undefined" !==
												typeof privacy_field_name
											) {
												formwise_data.field_name =
													privacy_field_name;
											}
										}
										break;
									case "radio":
										formwise_data.value = field.prop(
											"checked"
										)
											? field.val()
											: "";
										formwise_data.field_name = field
											.closest(".field-privacy_policy")
											.data("ref-id");
										break;
									default:
										formwise_data.value = field.val();
								}

								if (ur_includes(phone_id, field_name)) {
									formwise_data.value = field
										.siblings('input[type="hidden"]')
										.val();
								}
								break;
							case "select":
								formwise_data.value = field.val();
								break;
							case "textarea":
								switch (textarea_type) {
									case "wysiwyg":
										tinyMCE.triggerSave();
										formwise_data.value = field.val();
										break;
									default:
										formwise_data.value = field.val();
								}
								break;
							default:
						}

						$(document).trigger(
							"user_registration_frontend_form_data_render",
							[field, formwise_data]
						);
						formwise_data.field_type =
							"undefined" !== field.eq(0).attr("type")
								? field.eq(0).attr("type")
								: "null";
						if (field.attr("data-label") !== undefined) {
							formwise_data.label = field.attr("data-label");
						} else if (
							field.prev().length &&
							field.prev().get(0).tagName.toLowerCase() ===
								"label"
						) {
							formwise_data.label = field.prev().text();
						} else {
							formwise_data.label = formwise_data.field_type;
						}

						if (
							$.inArray(
								formwise_data.field_name,
								required_fields.join(",").trim()
							) >= 0
						) {
							available_field.push(formwise_data.field_name);
						}

						return formwise_data;
					},
					show_message: function (
						message,
						type,
						$submit_node,
						position
					) {
						$submit_node
							.closest(".user-registration")
							.find(".ur-message")
							.remove();

						// Check if the form is edit-profile form.
						if (
							$(".ur-frontend-form")
								.find("form.edit-profile")
								.hasClass("user-registration-EditProfileForm")
						) {
							var wrapper = $(
								'<div class="user-registration-' + type + '"/>'
							);
							wrapper.append(message);

							if ($("#user-registration").hasClass("vertical")) {
								wrapper.insertBefore(".ur-frontend-form");
							} else {
								wrapper.insertBefore(
									".user-registration-MyAccount-navigation"
								);
							}
						} else {
							var wrapper = $(
								'<div class="ur-message user-registration-' +
									type +
									'" id="ur-submit-message-node"/>'
							);
							if (type === "error") {
								var svgIcon =
									'<svg xmlns="http://www.w3.org/2000/svg" width="35" height="35" viewBox="0 0 35 35" fill="none">' +
									'    <g clip-path="url(#clip0_8269_857)">' +
									'        <path d="M10.4979 24.6391C14.4408 28.5063 20.7721 28.445 24.6394 24.5022C28.5066 20.5593 28.4453 14.2279 24.5025 10.3607C20.5596 6.49343 14.2283 6.55472 10.361 10.4976C6.49374 14.4404 6.55503 20.7718 10.4979 24.6391Z" stroke="#F25656" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>' +
									'        <path d="M20.3008 14.6445L14.699 20.3559" stroke="#F25656" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>' +
									'        <path d="M14.6445 14.6992L20.3559 20.301" stroke="#F25656" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>' +
									"    </g>" +
									"    <defs>" +
									'        <clipPath id="clip0_8269_857">' +
									'            <rect width="24" height="24" fill="white" transform="translate(17.3359 0.530273) rotate(44.4454)"></rect>' +
									"        </clipPath>" +
									"    </defs>" +
									"</svg>";

								wrapper.append(svgIcon);
							}
							wrapper.append(message);
							// Check the position set by the admin and append message accordingly.
							if ("1" === position) {
								$submit_node.append(wrapper);
							} else if ("2" === position) {
								if (type == "message") {
									$submit_node
										.closest(".entry-content")
										.prepend(wrapper);
									$submit_node
										.closest(".ur-frontend-form")
										.hide();
								} else {
									$submit_node.append(wrapper);
								}
							} else {
								$submit_node
									.closest(".ur-frontend-form")
									.prepend(wrapper);
							}
						}
					},
					/**
					 * Handles registration form submit and edit-profile form submit instances separately.
					 *
					 * @since  1.8.5
					 *
					 * @param element Element to search for in the template.
					 */
					separate_form_handler: function (element) {
						var field = "";

						// Check if the form is edit-profile form.
						if (
							$(".ur-frontend-form")
								.find("form.edit-profile")
								.hasClass("user-registration-EditProfileForm")
						) {
							field = $this
								.find(".user-registration-profile-fields")
								.find(".ur-edit-profile-field" + element);
						} else {
							field = $this
								.closest(".ur-frontend-form")
								.find(".ur-form-grid")
								.find(".ur-frontend-field" + element);
						}

						return field;
					},
					missing_attachment_handler: function name(file_upload) {
						var file_upload_field_array = [];

						//Check if file upload field exists.
						if (1 <= file_upload.length) {
							file_upload.each(function () {
								var file_upload_id = $(this).attr("id");

								if (
									$.inArray(
										file_upload_id,
										file_upload_field_array
									) === -1
								) {
									file_upload_field_array.push(
										file_upload_id
									);
								}
							});

							for (
								var i = 0;
								i < file_upload_field_array.length;
								i++
							) {
								var file_upload_val = $(
									"#" + file_upload_field_array[i]
								)
									.val()
									.split(",");

								for (
									var j = file_upload_val.length;
									j >= 0;
									j--
								) {
									if (!$.isNumeric(file_upload_val[j])) {
										file_upload_val.splice(j, 1);
									}
								}
								$("#" + file_upload_field_array[i]).val(
									file_upload_val
								);
							}
						}
					}
				};

				var events = {
					init: function () {
						this.form_submit_event();
						if (
							user_registration_params.ajax_submission_on_edit_profile
						) {
							this.edit_profile_event();
						}
					},
					/**
					 * Handles registration ajax form submission event.
					 *
					 */
					form_submit_event: function () {
						$(".ur-frontend-form").each(function () {
							var $registration_form = $(this);

							$registration_form
								.find("form.register")
								.off("submit")
								.on("submit", function (event) {
									event.preventDefault();

									// Prevent the form submission if submit button is hidden or disabled.
									if (
										$registration_form
											.find(
												"form.register button.ur-submit-button"
											)
											.is(":hidden") ||
										$registration_form
											.find(
												"form.register button.ur-submit-button"
											)
											.is(":disabled")
									) {
										return false;
									}
									var $this = $(this);

									// Validator messages.
									$.extend($.validator.messages, {
										required:
											user_registration_params.message_required_fields,
										url: user_registration_params.message_url_fields,
										email: user_registration_params.message_email_fields,
										number: user_registration_params.message_number_fields,
										confirmpassword:
											user_registration_params.message_confirm_password_fields
									});

									if (
										$this.find(
											".user-registration-password-strength"
										).length > 0
									) {
										var current_strength = $this
											.find(
												".user-registration-password-strength"
											)
											.attr("data-current-strength");
										var min_strength = $this
											.find(
												".user-registration-password-strength"
											)
											.attr("data-min-strength");

										if (
											parseInt(current_strength, 0) <
											parseInt(min_strength, 0)
										) {
											if (
												$this
													.find("#user_pass")
													.val() != ""
											) {
												$this
													.find("#user_pass-error")
													.remove();

												var error_msg_dom =
													'<label id="user_pass-error" class="user-registration-error" for="user_pass">' +
													ursL10n.password_strength_error +
													".</label>";
												$this
													.find(
														".user-registration-password-hint"
													)
													.after(error_msg_dom);
												$this
													.find("#user_pass")
													.attr("aria-invalid", true);
												$this
													.find("#user_pass")
													.trigger("focus");
											}

											return false;
										}
									}

									var $el = $this.find(
										".ur-smart-phone-field"
									);

									if ("true" === $el.attr("aria-invalid")) {
										var wrapper = $el.closest("p.form-row");
										wrapper
											.find(
												"#" + $el.data("id") + "-error"
											)
											.remove();
										var phone_error_msg_dom =
											'<label id="' +
											$el.data("id") +
											"-error" +
											'" class="user-registration-error" for="' +
											$el.data("id") +
											'">' +
											user_registration_params.message_validate_phone_number +
											"</label>";
										wrapper.append(phone_error_msg_dom);
										wrapper
											.find("#" + $el.data("id"))
											.attr("aria-invalid", true);
										return true;
									}

									// Remove word added by form filler in file upload field during submission
									var file_upload =
										$this.find(".urfu-file-input");

									form.missing_attachment_handler(
										file_upload
									);

									var exist_detail = $this
										.find(".uraf-profile-picture-upload")
										.find(
											".user-registration-error"
										).length;

									if (1 === exist_detail) {
										var profile = $this
											.find(
												".uraf-profile-picture-upload"
											)
											.find(
												".uraf-profile-picture-input"
											);
										var wrapper = $this.find(
											".uraf-profile-picture-upload"
										);
										wrapper
											.find(
												"#" +
													profile.attr("name") +
													"-error"
											)
											.remove();
										wrapper
											.find(
												".uraf-profile-picture-file-error"
											)
											.remove();
										var error_message =
											'<label id="' +
											profile.attr("name") +
											"-error" +
											'" class="user-registration-error" for="' +
											profile.attr("name") +
											'">' +
											user_registration_params.message_required_fields +
											"</label>";
										wrapper
											.find(
												"button.wp_uraf_profile_picture_upload"
											)
											.after(error_message);
									}
									//place it before user_registration_frontend_validate_before_form_submit trigger gets used by other addons so that regular validation is shown before addon validations
									if (!$this.valid()) {
										return;
									}
									$(document).trigger(
										"user_registration_frontend_validate_before_form_submit",
										[$this]
									);
									if (
										$(
											"#stripe-errors.user-registration-error:visible"
										).length
									) {
										return;
									}
									if (
										0 <
										$this.find(".dz-error-message").length
									) {
										return;
									}
									if (
										$this
											.find(
												"#user_registration_stripe_gateway"
											)
											.find(".user-registration-error")
											.length > 0 &&
										$this
											.find(
												"#user_registration_stripe_gateway"
											)
											.find(".user-registration-error")
											.is(":visible")
									) {
										return;
									}

									$this
										.find(".ur-submit-button")
										.prop("disabled", true);
									var form_data;
									var form_id = 0;
									var captchaResponse = "";
									var registration_language = "";
									if (
										"hCaptcha" ===
										user_registration_params.recaptcha_type
									) {
										captchaResponse = $this
											.find('[name="h-captcha-response"]')
											.val();
									} else if (
										"cloudflare" ===
										user_registration_params.recaptcha_type
									) {
										captchaResponse = $this
											.find(
												'[name="cf-turnstile-response"]'
											)
											.val();
									} else {
										captchaResponse = $this
											.find(
												'[name="g-recaptcha-response"]'
											)
											.val();
									}
									try {
										form_data = JSON.stringify(
											form.get_form_data(
												$this
													.closest(
														".ur-frontend-form"
													)
													.attr("id")
											)
										);
									} catch (ex) {
										form_data = "";
									}

									if (
										$(this)
											.closest("form")
											.find(
												'input[name="ur-user-form-id"]'
											).length === 1
									) {
										form_id = $(this)
											.closest("form")
											.find(
												'input[name="ur-user-form-id"]'
											)
											.val();
									}
									if (
										$(this)
											.closest("form")
											.find(
												'input[name="ur-registration-language"]'
											).length === 1
									) {
										registration_language = $(this)
											.closest("form")
											.find(
												'input[name="ur-registration-language"]'
											)
											.val();
									}

									// Append tax details if available
									var taxDetails =
										$(document).find("#ur-tax-details");

									if (taxDetails.length > 0) {
										form_data.tax_rate =
											taxDetails.data("tax-rate");
										form_data.tax_calculation_method =
											taxDetails.data(
												"tax-calculation-method"
											);
									}

									var data = {
										action: "user_registration_user_form_submit",
										form_data: form_data,
										captchaResponse: captchaResponse,
										form_id: form_id,
										registration_language:
											registration_language
									};

									var $error_message = {};
									$(document).trigger(
										"user_registration_frontend_before_form_submit",
										[data, $this, $error_message]
									);

									if (
										"undefined" !==
											typeof $error_message.message &&
										"" !== $error_message.message
									) {
										form.show_message(
											"<p>" +
												$error_message.message +
												"</p>",
											"error",
											$this,
											"1"
										);
										$this
											.find(".ur-submit-button")
											.prop("disabled", false);
										return;
									}

									if (
										$this
											.find(
												'.field-authorize_net_gateway[data-field-id="authorizenet_gateway"]'
											)
											.find(".ur-authorize-net-errors")
											.length > 0
									) {
										return;
									}

									$this
										.find(".ur-submit-button")
										.find("span")
										.addClass("ur-front-spinner");

									if (
										$registration_form
											.find(
												"form.register button.ur-submit-button"
											)
											.hasClass(
												"urm-update-membership-button"
											)
									) {
										$(document).trigger(
											"user_registration_membership_update_before_form_submit",
											[data, $this, $error_message]
										);
									} else {
										var hit_third_party_api =
											events.wait_third_party_api($this);
										if (hit_third_party_api) {
											var thirdPartyHandlerPromise =
												new Promise(function (
													resolve,
													reject
												) {
													$(document).trigger(
														"user_registration_third_party_api_before_form_submit",
														[
															data,
															$this,
															$error_message,
															resolve,
															reject
														]
													);
												}).then(function (val) {
													events.ajax_form_submit(
														val
													);
												});
										} else {
											events.ajax_form_submit(data);
										}
									}
								});
						});
					},
					/**
					 * Wait Form submission for third party api hit response.
					 *
					 */
					wait_third_party_api: function ($form) {
						var flag = false;
						if (
							$form.find(
								"#user_registration_authorize_net_gateway[data-gateway='authorize_net']:visible"
							).length > 0
						) {
							flag = true;
						}
						return flag;
					},
					/**
					 * Handles AJAX error responses for form submission.
					 *
					 * @param {Object} xhr - The XMLHttpRequest object
					 * @param {string} status - The status of the request
					 * @param {string} error - The error message
					 * @param {Object} $form - The jQuery form object
					 * @param {Object} posted_data - The data that was posted
					 */
					handle_ajax_error: function (
						xhr,
						status,
						error,
						$form,
						posted_data
					) {
						// Re-enable submit button
						$form.find(".ur-submit-button").prop("disabled", false);

						if (
							$form.find(".field-membership").length > 0 ||
							$form.find(".field-stripe_gateway").length > 0 ||
							$form.find(".field-authorize_net_gateway").length >
								0
						) {
							$form
								.find(".ur-submit-button")
								.find("span")
								.removeClass("ur-front-spinner");
							// Show fallback message
							form.show_message(
								user_registration_params.ajax_form_submit_error,
								"error",
								$form,
								"1"
							);
						} else {
							// Add all necessary hidden fields for fallback submission
							if (
								$form.find('input[name="ur_fallback_submit"]')
									.length === 0
							) {
								$form.append(
									'<input type="hidden" name="ur_fallback_submit" value="1" />'
								);
							}

							// Add action field
							if (
								$form.find('input[name="action"]').length === 0
							) {
								$form.append(
									'<input type="hidden" name="action" value="user_registration_user_form_submit" />'
								);
							}

							// Add form data
							if (
								$form.find('input[name="form_data"]').length ===
								0
							) {
								$form.append(
									'<input type="hidden" name="form_data" value="' +
										encodeURIComponent(
											posted_data.form_data
										) +
										'" />'
								);
							}

							// Add captcha response
							if (
								posted_data.captchaResponse &&
								$form.find('input[name="captchaResponse"]')
									.length === 0
							) {
								$form.append(
									'<input type="hidden" name="captchaResponse" value="' +
										posted_data.captchaResponse +
										'" />'
								);
							}

							// Add form ID
							if (
								posted_data.form_id &&
								$form.find('input[name="form_id"]').length === 0
							) {
								$form.append(
									'<input type="hidden" name="form_id" value="' +
										posted_data.form_id +
										'" />'
								);
							}

							// Add registration language
							if (
								posted_data.registration_language &&
								$form.find(
									'input[name="registration_language"]'
								).length === 0
							) {
								$form.append(
									'<input type="hidden" name="registration_language" value="' +
										posted_data.registration_language +
										'" />'
								);
							}

							// Submit the form traditionally
							$form[0].submit();
						}
					},
					/**
					 * Ajax form submission event.
					 *
					 */
					ajax_form_submit: function (posted_data) {
						var $form = $this;

						$.ajax({
							url: user_registration_params.ajax_url,
							data: posted_data,
							type: "POST",
							async: true,
							error: function (xhr, status, error) {
								events.handle_ajax_error(
									xhr,
									status,
									error,
									$form,
									posted_data
								);
							},
							complete: function (ajax_response) {
								$(document.body).trigger(
									"user_registration_after_form_submit_completion"
								);
								var ajaxFlag = [];
								ajaxFlag["status"] = true;

								var response_text = JSON.parse(
									ajax_response.responseText
								);
								if (
									response_text &&
									response_text.success &&
									posted_data &&
									posted_data.ur_authorize_net
								) {
									var response_data = response_text.data;
									var authorize_net_data = {
										ur_authorize_net:
											posted_data.ur_authorize_net
									};
									response_data = $.extend(
										{},
										response_data,
										authorize_net_data
									);
									response_text.data = response_data;
								}
								ajax_response.responseText =
									JSON.stringify(response_text);

								$(document).trigger(
									"user_registration_frontend_before_ajax_complete_success_message",
									[ajax_response, ajaxFlag, $this]
								);
								if (ajaxFlag["status"]) {
									$this
										.find(".ur-submit-button")
										.find("span")
										.removeClass("ur-front-spinner");

									var redirect_url = $this
										.find('input[name="ur-redirect-url"]')
										.val();
									var message = $('<ul class=""/>');
									var type = "error";
									var individual_field_message = false;

									try {
										var response = JSON.parse(
											ajax_response.responseText
										);

										var timeout =
											response &&
											response.data &&
											response.data.redirect_timeout !==
												undefined &&
											response.data.redirect_timeout !==
												null &&
											response.data.redirect_timeout !==
												""
												? response.data.redirect_timeout
												: 2000;

										if (
											typeof response.success !==
												"undefined" &&
											response.success === true &&
											typeof response.data
												.paypal_redirect !== "undefined"
										) {
											window.setTimeout(function () {
												window.location =
													response.data.paypal_redirect;
											}, timeout);
										}

										if (
											typeof response.success !==
												"undefined" &&
											response.success === true &&
											typeof response.data
												.mollie_redirect !== "undefined"
										) {
											window.setTimeout(function () {
												window.location =
													response.data.mollie_redirect;
											}, timeout);
										}

										if (
											typeof response.success !==
												"undefined" &&
											response.success === true
										) {
											type = "message";
										}

										if (type === "message") {
											// Membership/subscription registration: do not show "User successfully registered."; membership module shows "New member has been successfully created." only.
											if (
												response.data &&
												response.data
													.registration_type ===
													"membership"
											) {
												type = "membership_handled";
											}
										}
										if (type === "message") {
											$this
												.find(
													".user-registration-password-hint"
												)
												.remove();
											$this
												.find(
													".user-registration-password-strength"
												)
												.remove();

											if (
												response.data
													.form_login_option ==
												"admin_approval"
											) {
												message.append(
													"<li>" +
														ursL10n.user_under_approval +
														"</li>"
												);
											} else if (
												response.data
													.form_login_option ==
													"email_confirmation" ||
												response.data
													.form_login_option ==
													"admin_approval_after_email_confirmation"
											) {
												message.append(
													"<li>" +
														ursL10n.user_email_pending +
														"</li>"
												);
											} else if (
												response.data
													.form_login_option ==
													"payment" &&
												typeof response.data.message !==
													"undefined"
											) {
												message.append(
													"<li>" +
														response.data.message +
														"</li>"
												);
											} else {
												message.append(
													"<li>" +
														(typeof response.data
															.message ===
															"undefined")
														? ursL10n.user_successfully_saved
														: response.data
																.message +
																"</li>"
												);
											}

											if (
												"undefined" !==
												typeof response.data
													.auto_password_generation_success_message
											) {
												message.append(
													"<li>" +
														response.data
															.auto_password_generation_success_message +
														"</li>"
												);
											}
											$(".ur-input-count").text("0");
											if (
												!user_registration_params.ur_hold_data_before_redirection
											) {
												$this[0].reset();
											}
											if (
												$this.find("#profile_pic_url")
													.length
											) {
												$("#profile_pic_url").val("");
											}

											jQuery("#billing_country").trigger(
												"change"
											);
											jQuery("#shipping_country").trigger(
												"change"
											);

											if (
												"undefined" !==
												typeof response.data
													.role_based_redirect_url
											) {
												redirect_url =
													response.data
														.role_based_redirect_url;
											}
											if (
												typeof response.data
													.form_login_option !==
													"undefined" &&
												response.data
													.form_login_option ===
													"sms_verification"
											) {
												window.setTimeout(function () {
													if (
														typeof response.data
															.redirect_url !==
															"undefined" &&
														response.data
															.redirect_url
													) {
														window.location =
															response.data.redirect_url;
													}
												}, timeout);
											}

											if (
												"undefined" !==
													typeof redirect_url &&
												redirect_url !== ""
											) {
												$(document).trigger(
													"user_registration_frontend_before_redirect_url",
													[redirect_url]
												);

												window.setTimeout(function () {
													window.location =
														redirect_url;
												}, timeout);
											} else {
												if (
													typeof response.data
														.auto_login !==
														"undefined" &&
													response.data.auto_login
												) {
													$(document).trigger(
														"user_registration_frontend_before_auto_login"
													);
													window.setTimeout(
														function () {
															if (
																typeof response
																	.data
																	.redirect_url !==
																	"undefined" &&
																response.data
																	.redirect_url
															) {
																window.location =
																	response.data.redirect_url;
															} else {
																location.reload();
															}
														},
														timeout
													);
												}
											}
										} else if (type === "error") {
											if (
												typeof response.data.message ===
												"object"
											) {
												$.each(
													response.data.message,
													function (
														index,
														message_value
													) {
														if (
															message_value.hasOwnProperty(
																"individual"
															)
														) {
															var $field_id = [];
															$.each(
																$this
																	.find(
																		".ur-field-item"
																	)
																	.find(
																		".ur-frontend-field"
																	),
																function (
																	index
																) {
																	var $this =
																		$(this);

																	if (
																		$this.hasClass(
																			"input-captcha-icon-radio"
																		)
																	) {
																		var data_id =
																			$this.attr(
																				"data-id"
																			);

																		if (
																			!$field_id.includes(
																				data_id
																			)
																		) {
																			$field_id.push(
																				data_id
																			);
																		}
																	} else {
																		var $id =
																			$this.attr(
																				"id"
																			);
																		$field_id.push(
																			$id
																		);
																	}
																}
															);

															var field_name = "";

															$.each(
																message_value,
																function (
																	index,
																	value
																) {
																	var repeater_field_name =
																		"";
																	var repeater_row_id =
																		"";

																	if (
																		message_value.hasOwnProperty(
																			"repeater_field_name"
																		)
																	) {
																		repeater_field_name =
																			message_value.repeater_field_name;
																		repeater_row_id =
																			message_value.row_id.replace(
																				"row_",
																				""
																			);
																		index =
																			index +
																			"_" +
																			repeater_row_id;
																	}

																	if (
																		$field_id.includes(
																			index
																		)
																	) {
																		field_name =
																			index;

																		var error_message =
																			'<label id="' +
																			index +
																			"-error" +
																			'" class="user-registration-error" for="' +
																			index +
																			'">' +
																			value +
																			"</label>";

																		var wrapper =
																			"";

																		if (
																			$this.find(
																				".ur-repeater-row[data-repeater-field-name='" +
																					repeater_field_name +
																					"'][data-repeater-row='" +
																					repeater_row_id +
																					"'] "
																			)
																				.length >
																			0
																		) {
																			wrapper =
																				$this
																					.find(
																						".ur-repeater-row[data-repeater-field-name='" +
																							repeater_field_name +
																							"'][data-repeater-row='" +
																							repeater_row_id +
																							"'] "
																					)
																					.find(
																						".ur-field-item"
																					)
																					.find(
																						"input[id='" +
																							index +
																							"'], textarea[id='" +
																							index +
																							"']"
																					);
																		} else {
																			wrapper =
																				$this
																					.find(
																						".ur-form-row"
																					)
																					.find(
																						".ur-field-item"
																					)
																					.find(
																						"input[id='" +
																							index +
																							"'], textarea[id='" +
																							index +
																							"']"
																					);
																		}

																		wrapper
																			.closest(
																				".ur-field-item"
																			)
																			.find(
																				".user-registration-error"
																			)
																			.remove();
																		wrapper
																			.closest(
																				".form-row"
																			)
																			.append(
																				error_message
																			);
																		individual_field_message = true;
																	}
																}
															);
															$(document).trigger(
																"ur_handle_field_error_messages",
																[
																	$this,
																	field_name
																]
															);
														} else {
															message.append(
																"<li>" +
																	message_value +
																	"</li>"
															);
														}
													}
												);
											} else {
												message.append(
													"<li>" +
														response.data.message +
														"</li>"
												);
											}
										}
									} catch (e) {
										message.append(
											$("<li></li>").text(e.message)
										);
									}

									var success_message_position = JSON.parse(
										ajax_response.responseText
									).data.success_message_positon;

									if (!individual_field_message) {
										form.show_message(
											message,
											type,
											$this,
											success_message_position
										);
									} else {
										var $field_id = [];

										$.each(
											$this
												.find(".ur-field-item")
												.find(".ur-frontend-field"),
											function (index) {
												var $this = $(this);

												var $id = $this.attr("id");
												$field_id.push($id);
											}
										);
										var field_name = "";

										$.each(
											response.data.message,
											function (index, value) {
												if ($field_id.includes(index)) {
													field_name = index;
													var error_message =
														'<label id="' +
														index +
														"-error" +
														'" class="user-registration-error" for="' +
														index +
														'">' +
														value +
														"</label>";

													var wrapper = $this
														.find(".ur-field-item")
														.find(
															"input[id='" +
																index +
																"'], textarea[id='" +
																index +
																"']"
														);

													wrapper
														.closest(
															".ur-field-item"
														)
														.find(
															".user-registration-error"
														)
														.remove();
													wrapper
														.closest(".form-row")
														.append(error_message);
												}
											}
										);
										$(document).trigger(
											"ur_handle_field_error_messages",
											[$this, field_name]
										);
									}

									// Check the position set by the admin and scroll to the message postion accordingly.
									if ("1" === success_message_position) {
										// Scroll to the bottom on ajax submission complete.
										$(window).scrollTop(
											$this
												.find(".ur-button-container")
												.offset().top
										);
									} else {
										// Scroll to the top on ajax submission complete.
										$(window).scrollTop(
											$this
												.closest(".ur-frontend-form")
												.offset().top
										);
									}

									$(document).trigger(
										"user_registration_frontend_after_ajax_complete",
										[
											ajax_response.responseText,
											type,
											$this
										]
									);
									$this
										.find(".ur-submit-button")
										.prop("disabled", false);
								}

								$(".coupon-message").css({
									display: "none"
								});
							}
						});
					},
					/**
					 * Handles edit-profile ajax form submission event.
					 *
					 * @since  1.8.5
					 */
					edit_profile_event: function () {
						if (
							!user_registration_params.ajax_submission_on_edit_profile
						) {
							return;
						}
						$("form.user-registration-EditProfileForm")
							.off("submit")
							.on("submit", function (event) {
								event.preventDefault();
								event.stopImmediatePropagation();
								var $this = $(this);

								// Validator messages.
								$.extend($.validator.messages, {
									required:
										user_registration_params.message_required_fields,
									url: user_registration_params.message_url_fields,
									email: user_registration_params.message_email_fields,
									number: user_registration_params.message_number_fields
								});

								var $el = $this.find(".ur-smart-phone-field");

								if ("true" === $el.attr("aria-invalid")) {
									var wrapper = $el.closest("p.form-row");
									wrapper
										.find("#" + $el.data("id") + "-error")
										.remove();
									var phone_error_msg_dom =
										'<label id="' +
										$el.data("id") +
										"-error" +
										'" class="user-registration-error" for="' +
										$el.data("id") +
										'">' +
										user_registration_params.message_validate_phone_number +
										"</label>";
									wrapper.append(phone_error_msg_dom);
									wrapper
										.find("#" + $el.data("id"))
										.attr("aria-invalid", true);
									return true;
								}

								var exist_detail = $this
									.find(".uraf-profile-picture-upload")
									.find(".user-registration-error").length;

								if (1 === exist_detail) {
									var profile = $this
										.find(".uraf-profile-picture-upload")
										.find(".uraf-profile-picture-input");
									var wrapper = $this.find(
										".uraf-profile-picture-upload"
									);
									wrapper
										.find(
											"#" +
												profile.attr("name") +
												"-error"
										)
										.remove();
									wrapper
										.find(
											".uraf-profile-picture-file-error"
										)
										.remove();
									var error_message =
										'<label id="' +
										profile.attr("name") +
										"-error" +
										'" class="user-registration-error" for="' +
										profile.attr("name") +
										'">' +
										user_registration_params.message_required_fields +
										"</label>";
									wrapper
										.find(
											"button.wp_uraf_profile_picture_upload"
										)
										.after(error_message);
								}

								if (!$this.valid()) {
									return false;
								}

								var profile_picture_error = $this
									.find(
										".user-registration-profile-picture-error"
									)
									.find(".user-registration-error").length;
								if (1 === profile_picture_error) {
									return false;
								}

								event.preventDefault();
								$this
									.find(".user-registration-submit-Button")
									.prop("disabled", true);

								// Remove word added by form filler in file upload field during submission
								var file_upload =
									$this.find(".urfu-file-input");

								form.missing_attachment_handler(file_upload);

								var form_data;
								var form_nonce = "0";

								try {
									form_data = form.get_form_data();

									// Handle profile picture
									var profile_picture_url =
										$("#profile_pic_url").val();

									form_data.push({
										value: profile_picture_url,
										field_name:
											"user_registration_profile_pic_url"
									});

									form_data = JSON.stringify(form_data);
								} catch (ex) {
									form_data = "";
								}

								var data = {
									action: "user_registration_update_profile_details",
									security:
										user_registration_params.user_registration_profile_details_save,
									form_data: form_data
								};

								$(document).trigger(
									"user_registration_frontend_before_edit_profile_submit",
									[data, $this]
								);

								$this
									.find(".user-registration-submit-Button")
									.find("span")
									.addClass("ur-front-spinner");

								$.ajax({
									type: "POST",
									url: user_registration_params.ajax_url,
									dataType: "JSON",
									data: data,
									complete: function (ajax_response) {
										$(document).trigger(
											"user_registration_process_before_edit_profile_submit_completion"
										);
										$this
											.find("span.ur-front-spinner")
											.removeClass("ur-front-spinner");
										$this
											.closest(".user-registration")
											.find(".user-registration-error")
											.remove();
										$this
											.closest(".user-registration")
											.find(".user-registration-message")
											.remove();

										var message = $('<ul class=""/>');
										var type = "error";

										try {
											var response = JSON.parse(
												ajax_response.responseText
											);

											if (
												typeof response.success !==
													"undefined" &&
												response.success === true
											) {
												type = "message";
												if (
													typeof response.data
														.profile_pic_id !==
													"undefined"
												) {
													$this
														.find(
															".ur_removed_profile_pic"
														)
														.val("");

													if (
														$this.find(
															".uraf-profile-picture-remove"
														).length > 0
													) {
														$this
															.find(
																".uraf-profile-picture-remove"
															)
															.data(
																"attachment-id",
																response.data
																	.profile_pic_id
															);
													}
													if (
														$this.find(
															".profile-pic-remove"
														).length > 0
													) {
														$this
															.find(
																".profile-pic-remove"
															)
															.data(
																"attachment-id",
																response.data
																	.profile_pic_id
															);
													}
												}
											}

											var individual_field_message = false;
											if (
												typeof response.data.message ===
												"object"
											) {
												$.each(
													response.data.message,
													function (
														index,
														message_value
													) {
														if (
															message_value.hasOwnProperty(
																"individual"
															)
														) {
															var $field_id = [];
															$.each(
																$this
																	.find(
																		".ur-form-row"
																	)
																	.find(
																		".ur-field-item"
																	)
																	.find(
																		".ur-edit-profile-field"
																	),
																function (
																	index
																) {
																	var $this =
																		$(this);
																	var $id =
																		$this.attr(
																			"id"
																		);
																	$field_id.push(
																		$id
																	);
																}
															);

															$.each(
																message_value,
																function (
																	index,
																	value
																) {
																	var repeater_field_name =
																		"";
																	var repeater_row_id =
																		"";

																	if (
																		message_value.hasOwnProperty(
																			"repeater_field_name"
																		)
																	) {
																		repeater_field_name =
																			message_value.repeater_field_name;
																		repeater_row_id =
																			message_value.row_id.replace(
																				"row_",
																				""
																			);
																		index =
																			"user_registration_" +
																			index +
																			"_" +
																			repeater_row_id;
																	} else {
																		index =
																			index.indexOf(
																				"user_registration_"
																			) ===
																			-1
																				? "user_registration_" +
																					index
																				: index;
																	}

																	if (
																		$field_id.includes(
																			index
																		)
																	) {
																		var error_message =
																			'<label id="' +
																			index +
																			"-error" +
																			'" class="user-registration-error" for="' +
																			index +
																			'">' +
																			value +
																			"</label>";
																		var wrapper =
																			$this.find(
																				".ur-form-row"
																			);

																		if (
																			wrapper.hasClass(
																				"ur-repeater-row"
																			)
																		) {
																			wrapper =
																				wrapper
																					.find(
																						".ur-repeater-row[data-repeater-field-name='" +
																							repeater_field_name +
																							"'][data-repeater-row='" +
																							repeater_row_id +
																							"'] "
																					)
																					.find(
																						".ur-field-item"
																					)
																					.find(
																						"input[id='" +
																							index +
																							"'], textarea[id='" +
																							index +
																							"']"
																					);
																		} else {
																			wrapper =
																				wrapper
																					.find(
																						".ur-field-item"
																					)
																					.find(
																						"input[id='" +
																							index +
																							"'], textarea[id='" +
																							index +
																							"']"
																					);
																		}

																		wrapper
																			.closest(
																				".form-row"
																			)
																			.append(
																				error_message
																			);
																		individual_field_message = true;
																	}
																}
															);
														} else {
															message.append(
																"<li>" +
																	message_value +
																	"</li>"
															);
														}
													}
												);
											} else {
												message.append(
													"<li>" +
														response.data.message +
														"</li>"
												);
												if (
													undefined !==
													response.data
														.userEmailPendingMessage
												) {
													$(
														".user-registration-info.user-email-change-update-notice"
													).remove();
													form.show_message(
														$(
															'<ul class=""/>'
														).append(
															"<li>" +
																response.data
																	.userEmailUpdateMessage +
																"</li>"
														),
														"info user-email-change-update-notice",
														$this,
														"0"
													);

													if (
														$(
															"input#user_registration_user_email"
														).next(
															"div.email-updated"
														).length
													) {
														$(
															"input#user_registration_user_email"
														)
															.next(
																"div.email-updated"
															)
															.remove();
													}
													$(
														response.data
															.userEmailPendingMessage
													).insertAfter(
														$(
															"input#user_registration_user_email"
														)
													);
													$(
														"input#user_registration_user_email"
													).val(
														response.data
															.oldUserEmail
													);
												}
											}
										} catch (e) {
											message.append(
												$("<li></li>").text(e.message)
											);
										}

										if (!individual_field_message) {
											form.show_message(
												message,
												type,
												$this,
												"0"
											);
										}
										// Add trigger to handle functionalities that may be needed after edit-profile ajax submission submissions.
										$(document).trigger(
											"user_registration_edit_profile_after_ajax_complete",
											[ajax_response, $this]
										);
										$this
											.find(
												".user-registration-submit-Button"
											)
											.prop("disabled", false);

										// Scroll yo the top on ajax submission complete.
										$(window).scrollTop(
											$(".user-registration").position()
										);
									}
								}).fail(function () {
									form.show_message(
										"<p>" +
											user_registration_params.ajax_form_submit_error +
											"</p>",
										"error",
										$this,
										"1"
									);
									$this
										.find(
											".user-registration-submit-Button"
										)
										.prop("disabled", false);
									return;
								});
							});
					}
				};
				form.init();

				if ($(".user-registration-EditProfileForm ").length > 0) {
					form.get_form_data(
						$(".user-registration-EditProfileForm ").data("form-id")
					);
				}

				events.init();
			});
		};

		$(function () {
			// Initialize the flatpickr when the document is ready to be manipulated.
			$(document).ready(function () {
				// Handle user registration form submit event.
				$(".ur-submit-button").on("click", function () {
					$(this).closest("form.register").ur_form_submission();
				});

				if (
					user_registration_frontend_utils.ur_get_cookie(
						"urm_toast_content"
					) &&
					$(".user-registration-page .notice-container").length === 0
				) {
					$(document)
						.find(".user-registration-page")
						.prepend(
							'<div class="user-registration-membership-notice__container">' +
								'<div class="ur-toaster user-registration-membership-notice__red">' +
									'<span class="user-registration-membership-notice__message"></span>' +
									'<span class="user-registration-membership__close_notice">&times;</span>' +
								"</div>" +
							"</div>"
						);
				}

				var urm_toast_success_message =
					user_registration_frontend_utils.ur_get_cookie(
						"urm_toast_success_message"
					);

				// Displays the toast message.
				user_registration_frontend_utils.show_success_message(
					urm_toast_success_message
				);

				$(".user-registration-membership__close_notice").on(
					"click",
					function () {
						$(
							".user-registration-membership-notice__container"
						).hide();
					}
				);

				// Handle edit-profile form submit event.
				$(
					"input[name='save_account_details'], button[name='save_account_details']"
				)
					.off("click")
					.on("click", function (event) {
						event.preventDefault();
						// Check if the form is edit-profile form and check if ajax submission on edit profile is enabled.
						if (
							$(".ur-frontend-form")
								.find("form.edit-profile")
								.hasClass("user-registration-EditProfileForm")
						) {
							$(
								"form.user-registration-EditProfileForm"
							).ur_form_submission();
						}
						if (
							user_registration_params.ajax_submission_on_edit_profile
						) {
							$(this).submit();
						} else {
							$(this).closest("form")[0].submit();
						}
					});
				if ($(".ur-flatpickr-field").length) {
					// create an array to store the flatpickr instances.
					var flatpickrInstances = [];
					$(".ur-flatpickr-field").each(function () {
						var field = $(this);
						// check if flatpickr has already been initialized for the field.
						var instance = flatpickrInstances.find(function (i) {
							return i.element == field[0];
						});

						if (instance) {
							// flatpickr has already been initialized for the field, so open the instance.
							instance.open();
						} else {
							var field_id = field.attr("data-id");
							var formated_date = field
								.closest(".ur-field-item")
								.find("#formated_date")
								.val();

							if (0 < $(".ur-frontend-form").length) {
								var date_selector = $(
									".ur-frontend-form #" + field_id
								)
									.attr("type", "text")
									.val(formated_date);
							} else {
								var date_selector = $(
									".woocommerce-MyAccount-content #" +
										field_id
								)
									.attr("type", "text")
									.val(formated_date);
							}

							field.attr(
								"data-date-format",
								date_selector.data("date-format")
							);

							if (date_selector.data("mode")) {
								field.attr("data-mode", "range");
							}
							field.attr(
								"data-locale",
								date_selector.data("locale")
							);
							field.attr(
								"data-min-date",
								date_selector.data("min-date")
							);
							field.attr(
								"data-max-date",
								date_selector.data("max-date")
							);
							field.attr("data-default-date", formated_date);

							// flatpickr has not been initialized for the field, so create a new instance.
							instance = field.flatpickr({
								disableMobile: true,
								onChange: function (
									selectedDates,
									dateString,
									instance
								) {
									$("#" + field_id).val(dateString);
								}
							});

							flatpickrInstances.push(instance);
						}
					});
				}
			});

			// Handel WYSIWYG field client side validation.
			$(document).on("tinymce-editor-init", function (event, editor) {
				var $editorContainer = $(editor.getContainer());
				var containerId = $editorContainer.attr("id");

				var hiddenEditor = $("#" + containerId)
					.closest(".form-row")
					.find("[data-label = 'WYSIWYG']");

				editor.on("keyup", function (e) {
					hiddenEditor.val(tinyMCE.activeEditor.getContent());
				});
			});

			$(".ur-frontend-form").each(function () {
				var $registration_form = $(this).find("form.register");

				$registration_form.on(
					"focusout",
					"#user_pass, #password_1",
					function () {
						$this = $(this);
						var this_name = $(this).attr("name");
						var this_data_id = $(this).data("id");
						var enable_strength_password = $this
							.closest("form")
							.attr("data-enable-strength-password");

						if (enable_strength_password) {
							var wrapper = $this.closest("form");
							var minimum_password_strength = wrapper.attr(
								"data-minimum-password-strength"
							);
							var disallowedListArray = [];
							if (
								wp.passwordStrength &&
								"function" ===
									typeof wp.passwordStrength
										.userInputDisallowedList
							) {
								disallowedListArray =
									wp.passwordStrength.userInputDisallowedList();
							} else if (
								wp.passwordStrength &&
								"function" ===
									typeof wp.passwordStrength
										.userInputBlacklist
							) {
								disallowedListArray =
									wp.passwordStrength.userInputBlacklist();
							}

							disallowedListArray.push(
								wrapper
									.find('input[data-id="user_email"]')
									.val()
							); // Add email address in disallowedList.
							disallowedListArray.push(
								wrapper
									.find('input[data-id="user_login"]')
									.val()
							); // Add username in disallowedList.

							var strength = wp.passwordStrength?.meter(
								$this.val(),
								disallowedListArray
							);

							if (minimum_password_strength === "4") {
								strength = customPasswordChecks($this.val());
							}

							if (strength < minimum_password_strength) {
								if ($this.val() !== "") {
									wrapper
										.find("#" + this_data_id + "-error")
										.remove();
									var error_msg_dom =
										'<label id="' +
										this_data_id +
										'-error" class="user-registration-error" for="' +
										this_name +
										'">' +
										ursL10n.password_strength_error +
										".</label>";
									wrapper
										.find(
											".user-registration-password-hint"
										)
										.after(error_msg_dom);
								}
							}
						}
					}
				);
			});
		});
		/**
		 * Set the value of count in already exist details of field textarea.
		 */
		$(function () {
			$("textarea").each(function () {
				/**
				 * show the character and word count in textarea field.
				 */
				$(this).on("input", user_registration_count);
				var input_count;
				var selected_area_field = $(this).closest(".ur-field-item");
				if (selected_area_field.find(".ur-input-count").length > 0) {
					var selected_area_text = $(this).val().trim();
					if (
						selected_area_field
							.find(".ur-input-count")
							.data("count-type") === "characters"
					) {
						input_count = selected_area_text.length;
					} else {
						input_count =
							selected_area_text === ""
								? 0
								: selected_area_text.split(/\s+/).length;
					}
				}
				selected_area_field.find(".ur-input-count").text(input_count);
			});
		});
		/**
		 * Append a country option and Remove it on click, if the country is not allowed.
		 */
		$(function () {
			if (
				$(
					".user-registration-EditProfileForm.edit-profile .field-country"
				).length > 0
			) {
				$(".field-country").each(function () {
					var option_value = $(this)
						.find(".ur-data-holder")
						.data("option-value");
					var option_html = $(this)
						.find(".ur-data-holder")
						.data("option-html");
					var $select = $(this).find("select");

					if (option_value && option_html) {
						if (
							$select.find('option[value="' + option_value + '"]')
								.length === 0
						) {
							$select.append(
								"<option class='ur-remove' selected='selected' value='" +
									option_value +
									"'>" +
									option_html +
									"</option>"
							);
						}
						$(this).on("click", function () {
							$(this).find(".ur-remove").remove();
						});
					}
				});
			}
		});

		$(document).on("urm_show_action_message", function (e, data) {
			if (data.type == "error") {
				user_registration_frontend_utils.show_error_message(
					data.message
				);
			} else {
				user_registration_frontend_utils.show_success_message(
					data.message
				);
			}
		});
	};
	function user_registration_count() {
		$("textarea").each(function () {
			var input_count;
			var selected_area_field = $(this).closest(".ur-field-item");
			if (selected_area_field.find(".ur-input-count").length > 0) {
				var selected_area_text = $(this).val().trim();
				if (
					selected_area_field
						.find(".ur-input-count")
						.data("count-type") === "characters"
				) {
					input_count = selected_area_text.length;
				} else {
					input_count =
						selected_area_text === ""
							? 0
							: selected_area_text.split(/\s+/).length;
				}
			}
			selected_area_field.find(".ur-input-count").text(input_count);
		});
	}

	/**
	 * @since 2.0.0
	 *
	 * To check and uncheck all the option in checkbox.
	 */
	$(function () {
		$(".input-checkbox").each(function () {
			var checkAll = $(this).attr("data-id");
			if ("undefined" !== typeof checkAll) {
				if (
					$('input[name="' + checkAll + '[]"]:checked').length ==
					$('[data-id = "' + checkAll + '" ]').length
				) {
					$('[data-check = "' + checkAll + '" ]').prop(
						"checked",
						true
					);
				}
			}
		});

		$('input[type="checkbox"]#checkall').on("click", function () {
			var checkAll = $(this).attr("data-check");
			if ("undefined" !== typeof checkAll) {
				$('[data-id = "' + checkAll + '[]" ]').prop(
					"checked",
					$(this).prop("checked")
				);
			}
		});

		$(".input-checkbox").on("change", function () {
			var checkAll = $(this).attr("data-id");

			if ("undefined" !== typeof checkAll) {
				checkAll = checkAll.replace("[]", "");

				if ($(this).prop("checked") === false) {
					$('[data-check = "' + checkAll + '" ]').prop(
						"checked",
						false
					);
				}

				if (
					$('input[name="' + checkAll + '[]"]:checked').length ==
					$('[data-id = "' + checkAll + '" ]').length
				) {
					$('[data-check = "' + checkAll + '" ]').prop(
						"checked",
						true
					);
				}
			}
		});
	});
	user_registration_form_init();

	/**
	 * Reinitialize the form again after page is fully loaded,
	 * in order to support third party popup plugins.
	 *
	 * @since 1.9.0
	 */
	$(window).on("load", function () {
		user_registration_form_init();
	});
	$(window).on("user_registration_repeater_modified", function () {
		user_registration_form_init();
	});

	/**
	 * Reinitializes the form again in the elementor popup.
	 *
	 * @since 4.2.1
	 */
	window.addEventListener("load", function () {
		window.addEventListener("elementor/popup/show", function () {
			var forms = document.querySelectorAll(
				".elementor-popup-modal form.register:not(.elementor)"
			);
			forms.forEach(function (form) {
				user_registration_form_init();
				form.classList.add("elementor"); // Add class to prevent reinitialization
			});
		});
	});

	$(document).on(
		"click",
		"#login-registration--login,#login-registration--registration",
		function () {
			var action = $(this).data("action");
			if (action === "login" && !$(this).hasClass("active")) {
				$(this).addClass("active");
				$("#login-registration--registration").removeClass("active");
				$(".login-registration")
					.find(".ur-login-form")
					.removeClass("hidden");
				$(".login-registration")
					.find(".ur-registration-form")
					.addClass("hidden");
			}
			if (action === "registration" && !$(this).hasClass("active")) {
				$(this).addClass("active");
				$("#login-registration--login").removeClass("active");
				$(".login-registration")
					.find(".ur-login-form")
					.addClass("hidden");
				$(".login-registration")
					.find(".ur-registration-form")
					.removeClass("hidden");
			}
		}
	);

	$(document).on("change", ".ur-field-address-country", function (e) {
		e.stopPropagation();
		e.preventDefault();

		var $el = $(this);
		var fieldId = $el.data("id");
		var country = $el.val();
		var stateEnable = $el.data("state-enabled");

		if (!stateEnable) {
			return;
		}
		var data = {
			action: "user_registration_update_state_field",
			security:
				user_registration_params.user_registration_update_state_field,
			country: country
		};
		var $stateWrapper = $el.siblings(
			".ur-field-address-state-outer-wrapper"
		);

		$.ajax({
			type: "POST",
			url: user_registration_params.ajax_url,
			data: data,
			beforeSend: function () {
				$stateWrapper.empty();
				$stateWrapper.append('<span class="ur-front-spinner"></span>');
			},
			success: function (response) {
				var $stateElement;

				if (response.success && response.data.has_state && '' !== response.data.state) {
					var $select = $('<select class="ur-field-address-state select ur-frontend-field"></select>');
					$select.attr('name', fieldId + '_state');
					$select.append($($.parseHTML(response.data.state, null, false)).filter('option'));
					$stateElement = $select;
				} else {
					var $input = $('<input type="text" class="ur-field-address-state input-text ur-frontend-field"/>');
					$input.attr('name', fieldId + '_state');
					$stateElement = $input;
				}

				$( document ).find( '.ur-front-spinner' ).remove();

				$stateWrapper.append($stateElement);
				$stateElement.trigger('change');
			}
		});
	});

	/**
	 * Apply tax calculation
	 *
	 * @param $el
	 * @param country
	 * @param country_change
	 * @param $stateElement
	 *
	 * @since 5.0.0
	 */
	function apply_tax_calculation(
		$el,
		country,
		country_change,
		$stateElement
	) {
		var state = "";
		var regions = user_registration_params.regions_list.regions[country];
		var defaultRate = regions && regions.rate != null ? regions.rate : 0;
		var membershipData = {};

		if ($(document).find("#urm-membership-list").length) {
			membershipData = getMembershipData();
		} else {
			membershipData.total = $('.ur-total-amount[type="hidden"]').val();
		}

		if (country_change) {
			state = $stateElement.find("option:first").val();
		} else {
			state = $stateElement.val();
		}

		/**
		 * Check if country exists in regions list
		 * then check for states
		 * else apply default rate
		 */
		if (
			user_registration_params.regions_list.regions.hasOwnProperty(
				country
			)
		) {
			if (regions.hasOwnProperty("states") && "" !== state) {
				var states = regions.states;

				if (states.hasOwnProperty(state)) {
					let taxRate = states[state];
					calculate_total(membershipData, taxRate);
				} else {
					if (defaultRate !== undefined && defaultRate !== "") {
						calculate_total(membershipData, defaultRate);
					}
				}
			} else {
				calculate_total(membershipData, defaultRate);
			}
		} else {
			calculate_total(membershipData, defaultRate);
		}
		$("#ur-local-currency-switch-currency").trigger("change");
	}

	/**
	 * Get membership data
	 *
	 * @returns {{}}
	 *
	 * @since 5.0.0
	 */
	function getMembershipData() {
		var user_data = {};
		var form_inputs = $("#ur-membership-registration").find(
			"input.ur_membership_input_class"
		);

		form_inputs = convert_to_array(form_inputs);

		form_inputs.forEach(function (item) {
			var $this = $(item);

			if ($this.attr("name") !== undefined) {
				var name = $this.attr("name").toLowerCase().replace("urm_", "");
				user_data[name] = $this.val();
			}
		});

		var membership_input = $('input[name="urm_membership"]:checked');
		user_data.membership = membership_input.val();
		user_data.payment_method = "free";
		user_data.total = membership_input.data("urm-pg-calculated-amount");
		if (membership_input.data("urm-pg-type") !== "free") {
			user_data.payment_method = $(
				'input[name="urm_payment_method"]:checked:visible'
			).val();
		}
		var date = new Date();
		user_data.start_date =
			date.getFullYear() +
			"-" +
			(date.getMonth() + 1) +
			"-" +
			date.getDate();

		return user_data;
	}

	/**
	 * Calculate total with tax
	 *
	 * @param membershipData
	 * @param taxRate
	 *
	 * @since 5.0.0
	 */
	function calculate_total(membershipData, taxRate) {
		var total_input = $("#ur-membership-total");

		let membershipPrice = parseFloat(membershipData.total);
		let taxAmount = 0;
		if (user_registration_params.is_tax_calculation_activated) {
			taxAmount = (membershipPrice * taxRate) / 100;
		}
		let totalPrice = membershipPrice + taxAmount;
		let total = parseFloat(totalPrice).toFixed(2);

		$(".urm-membership-tax-value")
			.find(".ur_membership_input_label")
			.text(taxRate + "% Tax");
		var subTotalInput = $("#ur-membership-subtotal");
		var taxInput = $("#ur-membership-tax");

		if (total_input.length) {
			if ("left" === user_registration_params.currency_pos) {
				total_input.text(
					user_registration_params.currency_symbol + total
				);
				subTotalInput.text(
					user_registration_params.currency_symbol +
						membershipPrice.toFixed(2)
				);
				taxInput.text(
					user_registration_params.currency_symbol +
						taxAmount.toFixed(2)
				);
			} else {
				total_input.text(
					total + user_registration_params.currency_symbol
				);
				subTotalInput.text(
					membershipPrice.toFixed(2) +
						user_registration_params.currency_symbol
				);
				taxInput.text(
					taxAmount.toFixed(2) +
						user_registration_params.currency_symbol
				);
			}
		} else {
			total_input = $(".ur-total-amount[type='hidden']");
			total_input.val(total);
			$(document).find("span.ur-total-amount").text(total);
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
			user_registration_params.tax_calculation_method +
			'" ' +
			'data-total="' +
			total +
			'">';

		total_input.after(taxDetailsInput);
	}
})(jQuery);

function ur_includes(arr, item) {
	if (Array.isArray(arr)) {
		for (var i = 0; i < arr.length; i += 1) {
			if (arr[i] === item) {
				return true;
			}
		}
	}
	return false;
}

/**
 *
 * @param password
 * @returns {number}
 */
function customPasswordChecks(password) {
	var custom_password_params =
			ur_frontend_params_with_form_id.custom_password_params,
		minLength =
			custom_password_params.minimum_pass_length !== undefined &&
			custom_password_params.minimum_pass_length >= 3
				? custom_password_params.minimum_pass_length
				: 3,
		maxRepeatChars =
			custom_password_params.max_rep_chars !== undefined
				? custom_password_params.max_rep_chars
				: 0,
		canRepeatChars =
			custom_password_params.no_rep_chars !== undefined
				? custom_password_params.no_rep_chars
				: 0,
		minUppercaseCount =
			custom_password_params.minimum_uppercase !== undefined
				? custom_password_params.minimum_uppercase
				: 0,
		minSpecialCharCount =
			custom_password_params.minimum_special_chars !== undefined
				? custom_password_params.minimum_special_chars
				: 0,
		minimumDigitsCount =
			custom_password_params.minimum_digits !== undefined
				? custom_password_params.minimum_digits
				: 0,
		specialChars = new Set([
			"!",
			"@",
			"#",
			"$",
			"%",
			"^",
			"&",
			"*",
			"(",
			")",
			"-",
			"_",
			"=",
			"+",
			"{",
			"}",
			"[",
			"]",
			"|",
			"\\",
			":",
			";",
			'"',
			"'",
			"<",
			">",
			",",
			".",
			"?",
			"/"
		]),
		lastChar = "",
		repeatCount = 0,
		uppercaseCount = 0,
		digitCount = 0,
		specialCharCount = 0;

	if (password.length < minLength) {
		return 0;
	}

	for (var i = 0; i < password.length; i++) {
		var letter = password[i];
		// Check if the character is uppercase
		if (/[A-Z]/.test(letter)) {
			uppercaseCount++;
		}
		letter = letter.toLowerCase();
		// Check if the character is a digit
		if (/\d/.test(letter)) {
			digitCount++;
		}

		// Check if the character is a special character
		if (specialChars.has(letter)) {
			specialCharCount++;
		}

		// Check for repeated characters
		if (canRepeatChars && letter === lastChar) {
			repeatCount++;
			if (repeatCount >= maxRepeatChars) {
				return 0;
			}
		} else {
			repeatCount = 0; // Reset count if the character changes
		}
		lastChar = letter;
	}

	// Check if the password meets the required criteria
	if (minUppercaseCount > 0 && uppercaseCount < minUppercaseCount) {
		return 0;
	}
	if (minSpecialCharCount > 0 && specialCharCount < minSpecialCharCount) {
		return 0;
	}
	if (minimumDigitsCount > 0 && digitCount < minimumDigitsCount) {
		return 0;
	}
	return 4;
}

//Shows the content restriction message if botiga theme is used.
jQuery(document).ready(function ($) {
	var urcrContentRestrictMsg = $(document).find(".urcr-restrict-msg");
	if (urcrContentRestrictMsg.length > 1) {
		urcrContentRestrictMsg.hide().first().show();
	}
});

/**
 * Check if hello elementor theme is active or not to resolve flatpickr design issue.
 *
 */
jQuery(document).ready(function ($) {
	//Check the hello elementor theme is active or not through its stylesheet.
	var $isHelloElementorActive =
		$('link#hello-elementor-css[href*="themes/hello-elementor"]').length >
		0;

	if (!$isHelloElementorActive) {
		return;
	}

	$(document).on("focus", ".ur-flatpickr-field", function () {
		var $input = $(this);

		setTimeout(function () {
			$(".flatpickr-calendar:visible .flatpickr-current-month").css(
				"display",
				"flex"
			);
		}, 50);
	});
});
jQuery(document).ready(function ($) {
	$(".ur-submit-button.ur-disabled-btn").on("click", function (e) {
		e.preventDefault();
	});
});

/**
 * A function that converts an object to an array by taking its values, excluding the first two, and preserving the original order.
 *
 * @param {jQuery} $object - The jQuery object to be converted to an array.
 * @return {Array} The array with values from the object, excluding the first two, and in the original order.
 */
function convert_to_array($object) {
	return Object.values($object).reverse().slice(2).reverse();
}
