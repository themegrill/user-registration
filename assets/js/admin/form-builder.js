/**
 * UserRegistration Admin JS
 * global i18n_admin
 *
 * @since 2.0.0
 */
(function ($, user_registration_form_builder_data) {
	$(function () {
		var URFormBuilder = {
			/**
			 * Start the form builder.
			 */
			init: function () {
				var i18n_admin = user_registration_form_builder_data.i18n_admin;
				URFormBuilder.init_user_profile_modal();

				//Initialize Form Builder.
				URFormBuilder.init_form_builder();
				//Field option tab
				// $(document).on(
				// 	"click",
				// 	'ul.ur-tab-lists li[aria-controls="ur-tab-field-options"]',
				// 	function () {
				// 		// Hide the form settings in fields panel.
				// 		$(".ur-selected-inputs")
				// 			.find("form#ur-field-settings")
				// 			.hide();
				// 	}
				// );
				// Handle the field settings when a field is selected in the form builder.
				$(document).on("click", ".ur-selected-item", function () {
					URFormBuilder.handle_selected_item($(this));
				});
				// Run keyboard shortcuts action in form builder area only.
				if (user_registration_form_builder_data.is_form_builder) {
					$(window).on("keydown", function (event) {
						if (event.ctrlKey || event.metaKey) {
							if (
								"s" ===
									String.fromCharCode(
										event.which
									).toLowerCase() ||
								83 === event.which
							) {
								event.preventDefault();
								URFormBuilder.ur_save_form();
								return false;
							}
						}
					});
					// preview the form on key event
					$(window).on("keydown", function (e) {
						if (e.ctrlKey || e.metaKey) {
							if (
								"p" ===
									String.fromCharCode(
										e.which
									).toLowerCase() ||
								80 === e.which
							) {
								e.preventDefault();
								window.open(
									user_registration_form_builder_data.ur_preview
								);
							}
						}
					});
					// View user list table on key event.
					$(window).on("keydown", function (e) {
						if (e.ctrlKey || e.metaKey) {
							if (
								"u" ===
									String.fromCharCode(
										e.which
									).toLowerCase() ||
								85 === e.which
							) {
								e.preventDefault();
								window.open(
									user_registration_form_builder_data.ur_user_list_table
								);
							}
						}
					});
					// Show Keyboard Shortcuts Help Dialog Box on keypress.
					$(window).on("keydown", function (e) {
						if (e.ctrlKey || e.metaKey) {
							if (
								"h" ===
									String.fromCharCode(
										e.which
									).toLowerCase() ||
								85 === e.which
							) {
								e.preventDefault();
								URFormBuilder.ur_show_help();
								return false;
							}
						}
					});
				}

				// Show Help Dialog when quick link is clicked.
				$("#ur-keyboard-shortcut-link").on("click", function (e) {
					e.preventDefault();
					$(".ur-quick-links-content").toggle();
					URFormBuilder.ur_show_help();
				});

				$(".ur-quick-links-content__close-btn").on(
					"click",
					function (e) {
						e.preventDefault();
						$(".ur-quick-links-content").toggle();
					}
				);

				// Save the form when Update Form button is clicked.
				$(".ur_save_form_action_button").on("click", function () {
					URFormBuilder.ur_save_form();
				});

				//Embed the form in the page.
				$(".ur-embed-form-button").on("click", function () {
					if ($(this).find(".ur-spinner").length > 0) {
						return;
					}
					URFormBuilder.ur_embed_form($(this));
				});

				// Close validation message on form builder.
				$(document).on(
					"click",
					".ur-message .ur-message-close",
					function () {
						$message = $(this).closest(".ur-message");
						URFormBuilder.removeMessage($message);
					}
				);

				// Initialize the actions on choice field options.
				URFormBuilder.init_choice_field_options();

				// Show Help Dialog when new form is created.
				$(document).ready(function () {
					var queryString = window.location.search;
					var urlParams = new URLSearchParams(queryString);
					var urPage = urlParams.get("page");
					var isEditPage = urlParams.get("edit-registration");
					var formId = urlParams.get("form_id");
					var isTemplatePage = $(
						"#user-registration-form-templates"
					).length;

					var previousPage = document.referrer.split("page=")[1];
					var formUpdated =
						localStorage.getItem("formUpdated_" + isEditPage) ===
						"true";

					if (
						"add-new-registration" === urPage &&
						(null === isEditPage ||
							(null !== isEditPage &&
								"add-new-registration" === previousPage &&
								null !== formId)) &&
						0 === isTemplatePage &&
						!formUpdated
					) {
						$(".ur_save_form_action_button").text(
							user_registration_form_builder_data.i18n_publish_form_button_text
						);
						URFormBuilder.ur_show_help();
					}
				});

				// Toggle `Bulk Add` option.
				$(document.body).on(
					"click",
					".ur-toggle-bulk-options",
					function (e) {
						e.preventDefault();
						$this = $(this);

						var bulk_options_html = "";
						bulk_options_html +=
							'<div class="ur-bulk-options-container">';
						bulk_options_html +=
							'<div class="ur-general-setting ur-setting-textarea ur-general-setting-bulk-options ur-bulk-options-container"><label for="ur-type-textarea">' +
							$this.data("bulk-options-label") +
							'<span class="ur-portal-tooltip tooltipstered" data-tip="' +
							$this.data("bulk-options-tip") +
							'"></span></label>';
						bulk_options_html +=
							'<textarea data-field="description" class="ur-general-setting-field ur-type-textarea"></textarea></div>';
						bulk_options_html +=
							'<a class="button button-small ur-add-bulk-options" href="#">' +
							$this.data("bulk-options-button") +
							"</a></div>";

						if (
							$this.parent().next(".ur-bulk-options-container")
								.length
						) {
							if (
								$this
									.parent()
									.next(".ur-bulk-options-container")
									.css("display") === "none"
							) {
								$this
									.parent()
									.next(".ur-bulk-options-container")
									.show();
							} else {
								$this
									.parent()
									.next(".ur-bulk-options-container")
									.hide();
							}
						} else {
							$(bulk_options_html)
								.insertAfter($this.parent())
								.trigger("init_tooltips");
						}
					}
				);

				// Add custom list of options.
				$(document.body).on(
					"click",
					".ur-add-bulk-options",
					function (e) {
						e.preventDefault();
						var options = $(this).parent().next(".ur-options-list");
						var bulk_options_container = $(this).parent(
							".ur-bulk-options-container"
						);
						if (options.length) {
							var options_texts = bulk_options_container
								.find(".ur-type-textarea")
								.val()
								.replace(/<\/?[^>]+(>|$)/g, "")
								.split("\n");

							options_texts = $.unique(options_texts);

							options_texts.forEach(function (option_text) {
								if ("" !== option_text) {
									var $add_button = options
										.find("li")
										.last()
										.find("a.add");

									URFormBuilder.add_choice_field_option(
										$add_button,
										option_text.trim()
									);
								}
							});
							bulk_options_container
								.find(".ur-type-textarea")
								.val("");
						}
					}
				);
				$(document).on(
					"change",
					".ur-settings-enable-state",
					function (e) {
						var checked = $(this).is(":checked");
						var $wrapper = $(document).find(
							".ur-selected-item.ur-item-active"
						);

						if (checked) {
							$wrapper.find(".ur-state-container-wrapper").show();
						} else {
							$wrapper.find(".ur-state-container-wrapper").hide();
						}
					}
				);
			},
			init_user_profile_modal: function () {
				var user_profile_modal = {
					init: function () {
						$(document.body)
							.on("click", ".column-data_link a", this.add_item)
							.on("ur_backbone_modal_loaded", this.backbone.init)
							.on(
								"ur_backbone_modal_response",
								this.backbone.response
							);
					},
					add_item: function (e) {
						e.preventDefault();
						$(this).URBackboneModal({ template: "test-demo" });
						return false;
					},
					backbone: {
						init: function (e, target) {
							if ("test-demo" === target) {
							}
						},
						response: function (e, target) {
							if ("test-demo" === target) {
							}
						}
					}
				};
				user_profile_modal.init();
			},
			/**
			 * Handle the process of saving the form when called.
			 */
			ur_save_form: function () {
				var validation_response = URFormBuilder.get_validation_status();

				if (validation_response.validation_status === false) {
					URFormBuilder.show_message(validation_response.message);
					return;
				}
				if (typeof tinyMCE !== "undefined") {
					tinyMCE.triggerSave();
				}

				var form_data = URFormBuilder.get_form_data();
				var row_data = URFormBuilder.get_form_row_data();

				var stop_process = false;
				$.each(row_data, function () {
					if ($(this)[0].fields && $(this)[0].fields.length < 1) {
						URFormBuilder.show_message(
							user_registration_form_builder_data.form_repeater_row_empty
						);
						stop_process = true;
					}
				});

				if (stop_process) {
					return;
				}
				var form_row_ids = URFormBuilder.get_form_row_ids();
				var ur_form_id = $("#ur_form_id").val();
				var ur_form_id_localization =
					user_registration_form_builder_data.post_id;
				if (
					URFormBuilder.ur_parse_int(ur_form_id_localization, 0) !==
					URFormBuilder.ur_parse_int(ur_form_id, 0)
				) {
					ur_form_id = 0;
				}

				var exclude_serialize_setting_classes =
					".urcl-user-role-field, .uret-override-content-field, .ur_mailerlite_settings";

				var form_setting_data = $(
					"#ur-field-settings :not(" +
						exclude_serialize_setting_classes +
						")"
				).serializeArray();

				var conditional_roles_settings_data =
					URFormBuilder.get_form_conditional_role_data();
				var conditional_submit_settings_data =
					URFormBuilder.get_form_conditional_submit_data();
				var email_content_override_settings_data =
					URFormBuilder.get_form_email_content_override_data();
				var form_restriction_submit_data =
					URFormBuilder.get_form_restriction_submit_data();
				var calculation_settings =
					URFormBuilder.get_form_calculation_data();
				/** TODO:: Handle from multistep forms add-on if possible. */
				var multipart_page_setting = $(
					"#ur-multi-part-page-settings"
				).serializeArray();
				/** End Multistep form code. */

				var profile_completeness__custom_percentage = $(
					"#user_registration_profile_completeness_custom_percentage_field input, #user_registration_profile_completeness_custom_percentage_field select"
				).serializeArray();
				var form_restriction_extra_settings_data = $(
					"#urfr_max_limit_user_registration_value, #urfr_max_limit_user_registration_period, #urfr_password_restriction, #urfr_age_criteria_equation"
				).serializeArray();
				var data = {
					action: "user_registration_form_save_action",
					security: user_registration_form_builder_data.ur_form_save,
					data: {
						form_data: JSON.stringify(form_data),
						row_data: JSON.stringify(row_data),
						form_row_ids: JSON.stringify(form_row_ids),
						form_name: $("#ur-form-name").val(),
						form_id: ur_form_id,
						form_setting_data: form_setting_data,
						conditional_roles_settings_data:
							conditional_roles_settings_data,
						conditional_submit_settings_data:
							conditional_submit_settings_data,
						email_content_override_settings_data:
							email_content_override_settings_data,
						multipart_page_setting: multipart_page_setting,
						profile_completeness__custom_percentage:
							profile_completeness__custom_percentage,
						form_restriction_extra_settings_data:
							form_restriction_extra_settings_data,
						form_restriction_submit_data:
							form_restriction_submit_data,
						calculation_settings: calculation_settings
					}
				};

				$(document).trigger(
					"user_registration_admin_before_form_submit",
					[data]
				);
				var check_membership_validations =
					URFormBuilder.check_membership_validation(data);
				if (!check_membership_validations) return;
				// validation for unsupported currency by paypal.
				if (
					typeof data.data.ur_payment_disabled !== "undefined" &&
					data.data.ur_payment_disabled[0].validation_status === false
				) {
					URFormBuilder.show_message(
						data.data.ur_payment_disabled[0].validation_message
					);
					return;
				}

				// validation for unsupported currency by paypal.
				if (
					typeof data.data.ur_invalid_currency_status !==
						"undefined" &&
					data.data.ur_invalid_currency_status[0]
						.validation_status === false
				) {
					URFormBuilder.show_message(
						data.data.ur_invalid_currency_status[0]
							.validation_message
					);
					return;
				}

				//Google Sheet validation
				if (data.data.ur_google_sheets_integration !== undefined) {
					google_sheets_connections =
						data.data.ur_google_sheets_integration;

					// Send data only if username field is mapped.
					if (
						google_sheets_connections.length > 0 &&
						google_sheets_connections[0].hasOwnProperty(
							"mapped_fields"
						)
					) {
						var mapped_fields =
							google_sheets_connections[0]["mapped_fields"];
						if ($.isEmptyObject(mapped_fields)) {
							URFormBuilder.show_message(
								user_registration_form_builder_data.i18n_admin
									.i18n_google_sheets_sheet_empty_error
							);
							return;
						}
						var user_login_found = false;
						for (var key in mapped_fields) {
							if (
								mapped_fields.hasOwnProperty(key) &&
								mapped_fields[key] === "user_email"
							) {
								user_login_found = true;
								break;
							}
						}
						if (!user_login_found) {
							URFormBuilder.show_message(
								user_registration_form_builder_data.i18n_admin
									.i18n_google_sheets_user_email_missing_error
							);
							return;
						}
					}
				}

				// Profile Completeness validation.
				if (
					$(
						"#user_registration_profile_completeness_completion_percentage",
						$(document)
					).length != 0
				) {
					var sanitized_percent = parseFloat(
						$(
							"#user_registration_profile_completeness_completion_percentage",
							$(document)
						)
							.val()
							.replace(/[^\d\.]/g, "")
							.replace(/\.(([^\.]*)\.)*/g, ".$2")
					);

					if (sanitized_percent <= 0) {
						URFormBuilder.show_message(
							user_registration_form_builder_data.i18n_admin
								.i18n_pc_profile_completion_error
						);
						return;
					}

					var sum = 0;

					$.each(
						profile_completeness__custom_percentage,
						function (index, field) {
							if (
								field.name ==
									"user_registration_profile_completeness_custom_percentage_field[]" &&
								field.value !== ""
							) {
								sum += parseFloat(
									profile_completeness__custom_percentage[
										index + 1
									].value
								);
							}
						}
					);

					if (sum > sanitized_percent) {
						URFormBuilder.show_message(
							user_registration_form_builder_data.i18n_admin
								.i18n_pc_custom_percentage_filed_error
						);
						return;
					}
				}

				$.ajax({
					url: user_registration_form_builder_data.ajax_url,
					data: data,
					type: "POST",
					beforeSend: function () {
						var spinner =
							'<span class="ur-spinner is-active"></span>';
						$(".ur_save_form_action_button").append(spinner);
						$(".ur-notices").remove();
					},
					complete: function (response) {
						$(".ur_save_form_action_button")
							.find(".ur-spinner")
							.remove();
						if (!response.responseJSON) {
							return;
						}
						if (response.responseJSON.success === true) {
							var success_message =
								user_registration_form_builder_data.i18n_admin
									.i18n_form_successfully_saved;

							if (
								!user_registration_form_builder_data.is_edit_form
							) {
								var title = "Form successfully created.";
								message_body =
									"<p>Want to create a login form as well? Check this <a rel='noreferrer noopener' target='_blank' href='https://docs.wpuserregistration.com/registration-form-and-login-form/how-to-show-login-form/'>link</a>. To know more about other cool features check our <a rel='noreferrer noopener' target='_blank' href='https://docs.wpuserregistration.com/'>docs</a>.</p>";
								Swal.fire({
									icon: "success",
									title: title,
									html: message_body
								}).then(function (value) {
									if (0 === parseInt(ur_form_id)) {
										window.location =
											user_registration_form_builder_data.admin_url +
											response.responseJSON.data.post_id;
									}
								});
							} else {
								URFormBuilder.show_message(
									success_message,
									"success"
								);

								if (0 === parseInt(ur_form_id)) {
									window.location =
										user_registration_form_builder_data.admin_url +
										response.responseJSON.data.post_id;
								}
							}
						} else {
							var error = response.responseJSON.data.message;
							URFormBuilder.show_message(error);
						}
						$(".ur_save_form_action_button").text(
							user_registration_form_builder_data.i18n_update_form_button_text
						);
						localStorage.setItem("formUpdated_" + ur_form_id, true);
					}
				}).fail(function () {
					Swal.fire({
						icon: "error",
						title: user_registration_form_builder_data.ajax_form_submit_error_title,
						html:
							"<br />" +
							user_registration_form_builder_data.ajax_form_submit_error,
						customClass:
							"user-registration-swal2-modal user-registration-swal2-modal--center",
						confirmButtonText: "Troubleshoot",
						allowOutsideClick: false,
						showCloseButton: true
					}).then(function (result) {
						if (result.isConfirmed) {
							window.open(
								user_registration_form_builder_data.ajax_form_submit_troubleshooting_link
							);
						}
					});
					return;
				});
			},
			/**
			 * Handel the process of embedding the form.
			 */
			ur_embed_form: function ($this) {
				var data = {
					action: "user_registration_embed_page_list",
					security:
						user_registration_form_builder_data.ur_embed_page_list
				};

				$.ajax({
					url: user_registration_form_builder_data.ajax_url,
					data: data,
					type: "POST",
					beforeSend: function () {
						var spinner =
							'<span class="ur-spinner is-active"></span>';
						$this.append(spinner);
						$(".ur-notices").remove();
					},
					success: function (response) {
						$this.find(".ur-spinner").remove();
						function showInitialAlert() {
							var modelContent =
								'<div class=""><p>' +
								user_registration_form_builder_data.i18n_admin
									.i18n_embed_description +
								"</p></div>";

							Swal.fire({
								icon: "info",
								title: user_registration_form_builder_data
									.i18n_admin.i18n_embed_form_title,
								html: modelContent,
								showCancelButton: true,
								confirmButtonText:
									user_registration_form_builder_data
										.i18n_admin.i18n_embed_to_existing_page,
								cancelButtonText:
									user_registration_form_builder_data
										.i18n_admin.i18n_embed_to_new_page,
								showCloseButton: true,
								customClass:
									"user-registration-swal2-modal  user-registration user-registration-swal2-modal--center user-registrationswal2-icon-content-info user-registration-info swal2-show"
							}).then(function (result) {
								var form_id = $(".ur-embed-form-button").attr(
									"data-form_id"
								);

								if (result.isConfirmed) {
									showExistingPageSelection(
										response,
										form_id
									);
								} else if (
									result.dismiss === Swal.DismissReason.cancel
								) {
									showCreateNewPageForm(form_id);
								}
							});
						}
						function showExistingPageSelection(response, form_id) {
							var select_start =
								'<div class="ur-embed-select-existing-page-container"><p>' +
								user_registration_form_builder_data.i18n_admin
									.i18n_embed_existing_page_description +
								'</p><select style="width:100%; line-height:30px;" name="ur-embed-select-existing-page-name" id="ur-embed-select-existing-page-name">';
							var option =
								"<option disabled selected>Select Page</option>";
							response.data.forEach(function (page) {
								option +=
									'<option data-id="' +
									page.ID +
									'" value="' +
									page.ID +
									'">' +
									page.post_title +
									"</option>";
							});
							var select_end = "</select>";

							modelContent = select_start + option + select_end;
							Swal.fire({
								icon: "info",
								title: user_registration_form_builder_data
									.i18n_admin.i18n_embed_form_title,
								html: modelContent,
								showCloseButton: true,
								showCancelButton: true,
								cancelButtonText:
									user_registration_form_builder_data
										.i18n_admin.i18n_embed_go_back_btn,
								confirmButtonText:
									user_registration_form_builder_data
										.i18n_admin.i18n_embed_lets_go_btn,
								cancelButtonText:
									user_registration_form_builder_data
										.i18n_admin.i18n_embed_go_back_btn,
								customClass:
									"user-registration-swal2-modal  user-registration user-registration-swal2-modal--center user-registration-info swal2-show"
							}).then(function (result) {
								if (result.isDismissed) {
									showInitialAlert();
								} else if (result.isConfirmed) {
									var page_id = $(
										"#ur-embed-select-existing-page-name"
									).val();

									var data = {
										action: "user_registration_embed_form_action",
										security:
											user_registration_form_builder_data.ur_embed_action,
										page_id: page_id,
										form_id: form_id
									};
									$.ajax({
										url: user_registration_form_builder_data.ajax_url,
										type: "POST",
										data: data,
										success: function (response) {
											if (response.success) {
												window.location = response.data;
											}
										}
									});
								}
							});
						}
						function showCreateNewPageForm(form_id) {
							var description =
								'<div class="ur-embed-new-page-container"><p>' +
								user_registration_form_builder_data.i18n_admin
									.i18n_embed_new_page_description +
								"</p>";
							var page_name =
								'<div style="width: 100%"><input style="width:100%" type="text" name="page_title" /></div>';

							modelContent = description + page_name;
							Swal.fire({
								icon: "info",
								title: user_registration_form_builder_data
									.i18n_admin.i18n_embed_form_title,
								html: modelContent,
								showCancelButton: true,
								confirmButtonText:
									user_registration_form_builder_data
										.i18n_admin.i18n_embed_lets_go_btn,
								cancelButtonText:
									user_registration_form_builder_data
										.i18n_admin.i18n_embed_go_back_btn,
								customClass:
									"user-registration-swal2-modal  user-registration user-registration-swal2-modal--center user-registration-info swal2-show"
							}).then(function (result) {
								if (result.isDismissed) {
									showInitialAlert();
								} else if (result.isConfirmed) {
									var page_title = $(
										"[name='page_title']"
									).val();

									var data = {
										action: "user_registration_embed_form_action",
										security:
											user_registration_form_builder_data.ur_embed_action,
										page_title: page_title,
										form_id: form_id
									};
									$.ajax({
										url: user_registration_form_builder_data.ajax_url,
										type: "POST",
										data: data,
										success: function (response) {
											if (response.success) {
												window.location = response.data;
											}
										}
									});
								}
							});
						}
						showInitialAlert();
					}
				});
			},

			/**
			 * Show Help Popup
			 */
			ur_show_help: function () {
				if (!$(".jconfirm").length) {
					var shortcut_keys_html = "<ul>";

					$.each(
						user_registration_form_builder_data.i18n_shortcut_keys,
						function (key, value) {
							shortcut_keys_html +=
								'<li class="ur-shortcut-keyword"><div class="ur-shortcut-title">' +
								value +
								'</div><div class="ur-key"><span class="ur-key-ctrl">' +
								key.split("+")[0] +
								'</span><i class="ur-key-plus"> + </i><span class="ur-key-character"><b>' +
								key.split("+")[1] +
								"</b></span></div></li>";
						}
					);

					shortcut_keys_html += "</ul>";

					jc = $.dialog({
						title: user_registration_form_builder_data.i18n_shortcut_key_title,
						content: shortcut_keys_html,
						icon: "dashicons dashicons-info",
						type: "blue",
						useBootstrap: "false",
						boxWidth: "550px",
						buttons: {
							confirm: {
								text: user_registration_form_builder_data.i18n_close,
								btnClass: "btn-confirm",
								keys: ["enter"]
							}
						},
						escapeKey: true,
						backgroundDismiss: function () {
							return true;
						},
						theme: "material"
					});
				} else {
					jc.close();
				}
			},
			/**
			 * Returns all the validation messages for the specific form in form builder.
			 */
			get_validation_status: function () {
				var only_one_field_index = $.makeArray(
					user_registration_form_builder_data.form_one_time_draggable_fields
				);

				var required_fields = $.makeArray(
					user_registration_form_builder_data.form_required_fields
				);

				if (
					$("#user_registration_pro_auto_password_activate").is(
						":checked"
					)
				) {
					required_fields.splice(
						required_fields.indexOf("user_pass"),
						1
					);
				}

				var response = {
					validation_status: true,
					message: ""
				};
				if ($(".ur-selected-item").length === 0) {
					response.validation_status = false;
					response.message =
						user_registration_form_builder_data.i18n_admin.i18n_at_least_one_field_need_to_select;
					return response;
				}
				if ($("#ur-form-name").val() === "") {
					response.validation_status = false;
					response.message =
						user_registration_form_builder_data.i18n_admin.i18n_empty_form_name;
					return response;
				}

				if (
					$("#user_registration_enable_stripe").is(":checked") &&
					$("#user_registration_enable_stripe_recurring").is(
						":checked"
					) &&
					$(".ur-input-type-coupon-field").length > 0
				) {
					$("#user_registration_enable_stripe_recurring").prop(
						"checked",
						false
					);
					response.validation_status = false;
					response.message =
						user_registration_form_builder_data.i18n_admin.i18n_no_stripe_for_coupon;
					return response;
				}

				if (
					$(".ur_save_form_action_button").find(".ur-spinner")
						.length > 0
				) {
					response.validation_status = false;
					response.message =
						user_registration_form_builder_data.i18n_admin.i18n_previous_save_action_ongoing;
					return response;
				}
				if (
					$(
						"#user_registration_form_setting_minimum_password_strength_Custom"
					).is(":checked")
				) {
					var password_length = $(
						"#user_registration_form_setting_form_minimum_pass_length"
					).val();
					if (password_length < 6) {
						response.validation_status = false;
						response.message =
							user_registration_form_builder_data.i18n_admin.i18n_min_custom_password_length_error;
						return response;
					}
					var custom_fields = [
						"minimum_uppercase",
						"minimum_digits",
						"minimum_special_chars",
						"max_char_repeat_length"
					];
					custom_fields.forEach(function (value) {
						var max_repeat_length = $(
							"#user_registration_form_setting_form_" + value
						).val();
						if (max_repeat_length < 0) {
							response.validation_status = false;
							var formattedString = value.replace(/_/g, " ");
							function capitalizeFirstLetter(string) {
								return (
									string.charAt(0).toUpperCase() +
									string.slice(1)
								);
							}
							formattedString =
								capitalizeFirstLetter(formattedString);
							response.message =
								formattedString +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_custom_password_negative_value_error;
							return response;
						}
					});
				}

				$.each(
					$(
						".ur-selected-item select.ur-settings-selected-countries"
					),
					function () {
						var selected_countries = $(this).val();
						if (
							!selected_countries ||
							(Array.isArray(selected_countries) &&
								selected_countries.length === 0)
						) {
							response.validation_status = false;
							response.message =
								user_registration_form_builder_data.i18n_admin.i18n_select_countries;
							return response;
						}
					}
				);
				$.each(
					$(
						'.ur-input-grids .ur-general-setting-block input[data-field="field_name"]'
					),
					function () {
						var $field = $(this);
						var need_to_break = false;
						var field_attribute;
						try {
							var field_value = $field.val();
							var length = $(
								".ur-input-grids .ur-advance-setting-block"
							).find(
								'input[data-field="field_name"][value="' +
									field_value +
									'"]'
							).length;
							if (length > 1) {
								throw user_registration_form_builder_data
									.i18n_admin.i18n_duplicate_field_name;
							}
							if (
								$field
									.closest(".ur-general-setting-block")
									.find('input[data-field="label"]')
									.val() === ""
							) {
								$field = $field
									.closest(".ur-general-setting-block")
									.find('input[data-field="label"]');
								throw user_registration_form_builder_data
									.i18n_admin.i18n_empty_field_label;
							}
							var field_regex =
								/[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/gm;
							var regex_result = field_value.match(field_regex);
							if (
								regex_result !== null &&
								regex_result.length === 1 &&
								regex_result[0] === field_value
							) {
							} else {
								throw user_registration_form_builder_data
									.i18n_admin.i18n_invald_field_name;
							}
						} catch (err) {
							response.validation_status = false;
							response.message =
								err.message === undefined ? err : err.message;
							$field
								.closest(".ur-selected-item")
								.trigger("click");
							field_attribute = $field.attr("data-field");
							$("#ur-setting-form")
								.find(
									'input[data-field="' +
										field_attribute +
										'"]'
								)
								.css({ border: "1px solid red" });
							setTimeout(function () {
								$("#ur-setting-form")
									.find(
										'input[data-field="' +
											field_attribute +
											'"]'
									)
									.removeAttr("style");
							}, 2000);
							need_to_break = true;
						}
						if (need_to_break) {
							return false;
						}
					}
				);
				for (
					var single_field = 0;
					single_field < only_one_field_index.length;
					single_field++
				) {
					if (
						$(".ur-input-grids").find(
							'.ur-field[data-field-key="' +
								only_one_field_index[single_field] +
								'"]'
						).length > 1
					) {
						response.validation_status = false;
						response.message =
							user_registration_form_builder_data.i18n_admin
								.i18n_multiple_field_key +
							only_one_field_index[single_field];
						break;
					}
				}
				var login_options = $(
					"#user_registration_form_setting_login_options"
				).val();

				if ("sms_verification" === login_options) {
					var phone_fields = ["phone_fields"];
					required_fields = required_fields.concat(phone_fields);
				}

				var paypal = $("#user_registration_enable_paypal_standard");
				var stripe = $("#user_registration_enable_stripe");
				var anet = $("#user_registration_enable_authorize_net");
				var mollie = $("#user_registration_enable_mollie");
				if (paypal.is(":checked")) {
					var payment_fields = ["payment_fields"];

					required_fields = required_fields.concat(payment_fields);
				} else {
					if (stripe.is(":checked")) {
						var stripe_fields = [
							"payment_fields",
							"stripe_gateway"
						];

						required_fields = required_fields.concat(stripe_fields);
					} else if (anet.is(":checked")) {
						var anet_fields = [
							"payment_fields",
							"authorize_net_gateway"
						];

						required_fields = required_fields.concat(anet_fields);
					} else if (mollie.is(":checked")) {
						var mollie_fields = ["payment_fields"];

						required_fields = required_fields.concat(mollie_fields);
					}
				}
				for (
					var required_index = 0;
					required_index < required_fields.length;
					required_index++
				) {
					if (required_fields[required_index] === "phone_fields") {
						var phone = $(".ur-input-grids").find(
							'.ur-field[data-field-key="phone"]'
						).length;

						if (phone < 1) {
							response.validation_status = false;

							var field =
								user_registration_form_builder_data.i18n_admin
									.i18n_phone_field;

							response.message =
								field +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_field_is_required;
							break;
						} else {
							var phone_field = $(
								"#user_registration_form_setting_default_phone_field option:selected"
							);
							var phone_format =
								phone_field.attr("data-phone-format");

							if (
								"undefined" === typeof phone_format ||
								"smart" === phone_format
							) {
								continue;
							} else {
								response.validation_status = false;
								response.message =
									user_registration_form_builder_data.i18n_admin.i18n_smart_phone_field;
								break;
							}
						}
					} else if (
						required_fields[required_index] === "payment_fields"
					) {
						var multiple_choice = $(".ur-input-grids").find(
							'.ur-field[data-field-key="multiple_choice"]'
						).length;
						var subscription_plan = $(".ur-input-grids").find(
							'.ur-field[data-field-key="subscription_plan"]'
						).length;
						var single_item = $(".ur-input-grids").find(
							'.ur-field[data-field-key="single_item"]'
						).length;
						var payment_slider = $(".ur-input-grids").find(
							".ur-payment-slider-sign:visible"
						).length;

						if (
							subscription_plan < 1 &&
							multiple_choice < 1 &&
							single_item < 1 &&
							payment_slider < 1
						) {
							response.validation_status = false;

							var field =
								user_registration_form_builder_data.i18n_admin
									.i18n_payment_field;

							response.message =
								field +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_field_is_required;
							break;
						}
					} else {
						if (
							$(".ur-input-grids").find(
								'.ur-field[data-field-key="' +
									required_fields[required_index] +
									'"]'
							).length === 0
						) {
							response.validation_status = false;

							if (required_index === 0) {
								var field =
									user_registration_form_builder_data
										.i18n_admin.i18n_user_email;
							} else if (required_index === 1) {
								var field =
									user_registration_form_builder_data
										.i18n_admin.i18n_user_password;
							} else {
								if (
									"authorize_net_gateway" ===
									required_fields[required_index]
								) {
									var field =
										user_registration_form_builder_data
											.i18n_admin.i18n_anet_field;
								} else {
									var field =
										user_registration_form_builder_data
											.i18n_admin.i18n_stripe_field;
								}
							}

							response.message =
								field +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_field_is_required;
							break;
						}
					}
				}

				$.each(
					$(".ur-input-grids").find(
						'.ur-field[data-field-key="text"]'
					),
					function () {
						var $size_field = $(this)
							.closest(".ur-selected-item")
							.find(".ur-advance-setting-block .ur-settings-size")
							.val();
						var label = $(this)
							.closest(".ur-selected-item")
							.find(".ur-label label")
							.html();

						if ($size_field < 1) {
							response.validation_status = false;
							response.message =
								label +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_input_size;
						}
					}
				);
				$.each(
					$(".ur-input-grids").find(
						'.ur-field[data-field-key="password"]'
					),
					function () {
						var $size_field = $(this)
							.closest(".ur-selected-item")
							.find(".ur-advance-setting-block .ur-settings-size")
							.val();
						var label = $(this)
							.closest(".ur-selected-item")
							.find(".ur-label label")
							.html();
						if ($size_field < 1) {
							response.validation_status = false;
							response.message =
								label +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_input_size;
						}
					}
				);

				$.each(
					$(".ur-input-grids").find(
						'.ur-field[data-field-key="file"]'
					),
					function () {
						var $maximum_number_limit_on_uploads = $(this)
							.closest(".ur-selected-item")
							.find(
								".ur-general-setting-block .ur-general-setting-maximum-number-limit-on-uploads input"
							)
							.val();
						var label = $(this)
							.closest(".ur-selected-item")
							.find(".ur-label label")
							.html();
						if ($maximum_number_limit_on_uploads < 1) {
							response.validation_status = false;
							response.message =
								label +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_input_size;
						}

						var $max_upload_size = $(this)
							.closest(".ur-selected-item")
							.find(
								".ur-advance-setting-block input[data-id='file_advance_setting_max_upload_size']"
							)
							.val();

						var max_upload_size_ini =
							user_registration_form_builder_data.max_upload_size_ini;

						if (
							parseInt($max_upload_size) >
							parseInt(max_upload_size_ini)
						) {
							response.validation_status = false;
							response.message =
								label +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_max_upload_size;
						}
					}
				);

				$.each(
					$(".ur-input-grids").find(
						'.ur-field[data-field-key="number"]'
					),
					function () {
						var $size_field = $(this)
							.closest(".ur-selected-item")
							.find(".ur-advance-setting-block .ur-settings-size")
							.val();
						var label = $(this)
							.closest(".ur-selected-item")
							.find(".ur-label label")
							.html();

						if ($size_field < 1) {
							response.validation_status = false;
							response.message =
								label +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_input_size;
						}
						var $min = $(this)
							.closest(".ur-selected-item")
							.find(".ur-advance-setting-block .ur-settings-min")
							.val();
						var $max = $(this)
							.closest(".ur-selected-item")
							.find(".ur-advance-setting-block .ur-settings-max")
							.val();

						if (parseFloat($min) > parseFloat($max)) {
							response.validation_status = false;
							response.message =
								label +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_min_max_input;
						}
					}
				);

				$.each(
					$(".ur-input-grids").find(
						'.ur-field[data-field-key="text"], .ur-field[data-field-key="first_name"], .ur-field[data-field-key="description"],.ur-field[data-field-key="display_name"], .ur-field[data-field-key="last_name"]'
					),
					function () {
						var max_length_enabled = $(this)
								.closest(".ur-selected-item")
								.find(
									".ur-advance-setting-block .ur-settings-limit-length"
								)
								.is(":checked"),
							min_length_enabled = $(this)
								.closest(".ur-selected-item")
								.find(
									".ur-advance-setting-block .ur-settings-minimum-length"
								)
								.is(":checked"),
							max_length_limit_length = $(this)
								.closest(".ur-selected-item")
								.find(
									".ur-advance-setting-block .ur-settings-limit-length-limit-count"
								)
								.val(),
							min_length_limit_length = $(this)
								.closest(".ur-selected-item")
								.find(
									".ur-advance-setting-block .ur-settings-minimum-length-limit-count"
								)
								.val();

						var label = $(this)
							.closest(".ur-selected-item")
							.find(".ur-label label")
							.html();

						if (min_length_enabled) {
							if (
								min_length_limit_length === "" ||
								isNaN(min_length_limit_length) ||
								parseInt(min_length_limit_length, 10) < 1
							) {
								response.validation_status = false;
								response.message =
									user_registration_form_builder_data
										.i18n_admin.invalid_min_length +
									" " +
									label;
							}
						}
						if (max_length_enabled) {
							if (
								max_length_limit_length === "" ||
								isNaN(max_length_limit_length) ||
								parseInt(max_length_limit_length, 10) < 1
							) {
								response.validation_status = false;
								response.message =
									user_registration_form_builder_data
										.i18n_admin.invalid_max_length +
									" " +
									label;
							}
						}

						if (max_length_enabled && min_length_enabled) {
							var max_length_limit_mode = $(this)
									.closest(".ur-selected-item")
									.find(
										".ur-advance-setting-block .ur-settings-limit-length-limit-mode"
									)
									.val(),
								min_length_limit_mode = $(this)
									.closest(".ur-selected-item")
									.find(
										".ur-advance-setting-block .ur-settings-minimum-length-limit-mode"
									)
									.val();

							if (
								max_length_limit_mode === min_length_limit_mode
							) {
								if (
									parseInt(min_length_limit_length, 10) >
									parseInt(max_length_limit_length, 10)
								) {
									response.validation_status = false;
									response.message =
										user_registration_form_builder_data
											.i18n_admin
											.min_length_less_than_max_length +
										" " +
										label;
								}
							} else {
								response.validation_status = false;
								response.message =
									user_registration_form_builder_data.i18n_admin.i18n_min_max_mode.replace(
										"%field%",
										label
									);
							}
						}
					}
				);

				$.each(
					$(".ur-input-grids").find(
						'.ur-field[data-field-key="timepicker"]'
					),
					function () {
						var $time_interval = $(this)
							.closest(".ur-selected-item")
							.find(
								".ur-advance-setting-block .ur-settings-time_interval"
							)
							.val();

						var $time_format = $(this)
							.closest(".ur-selected-item")
							.find(
								".ur-advance-setting-block .ur-settings-time_format"
							)
							.val();

						var label = $(this)
							.closest(".ur-selected-item")
							.find(".ur-label label")
							.html();
						if ($time_interval < 1) {
							response.validation_status = false;
							response.message =
								label +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_input_size;
						}
					}
				);

				$.each(
					$(".ur-input-grids").find(
						'.ur-field[data-field-key="range"]'
					),
					function () {
						var $size_field = $(this)
							.closest(".ur-selected-item")
							.find(".ur-advance-setting-block .ur-settings-step")
							.val();
						var label = $(this)
							.closest(".ur-selected-item")
							.find(".ur-label label")
							.html();

						if ($size_field < 1) {
							response.validation_status = false;
							response.message =
								label +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_input_size;
						}
						var $min = $(this)
							.closest(".ur-selected-item")
							.find(".ur-advance-setting-block .ur-settings-min")
							.val();
						var $max = $(this)
							.closest(".ur-selected-item")
							.find(".ur-advance-setting-block .ur-settings-max")
							.val();

						if (parseFloat($min) > parseFloat($max)) {
							response.validation_status = false;
							response.message =
								label +
								" " +
								user_registration_form_builder_data.i18n_admin
									.i18n_min_max_input;
						}
					}
				);

				if (
					$("#urfr_enable_verification").is(":checked") &&
					$("#urfr_verification_type").val() === "qna"
				) {
					// Validate empty fields for question and answer block.
					if ($(".urfr-qna-question-wrapper").length < 1) {
						response.validation_status = false;
						response.message =
							user_registration_form_builder_data.i18n_admin.i18n_urfr_field_required_error;
					}

					$.each($(".urfr-qna-block"), function () {
						var questionValue = $(this)
							.find("input[name='urfr_qna_question']")
							.val();
						var answer = $(this)
							.find("input[name='urfr_qna_answer']")
							.val();
						if (questionValue === "" || answer === "") {
							response.validation_status = false;
							response.message =
								user_registration_form_builder_data.i18n_admin.i18n_urfr_qna_field_empty_error;
						}
					});
				}
				return response;
			},
			/**
			 * Show all the validation messages while saving the form in form builder.
			 *
			 * @param string message Specific validation message.
			 * @param string type The type or status of message, i.e. success or failure
			 */
			show_message: function (message, type) {
				var $message_container = $(".ur-form-container").find(
						".ur-builder-message-container"
					),
					$admin_bar = $("#wpadminbar"),
					message_string = "";

				if (0 === $message_container.length) {
					$(".ur-form-container").append(
						'<div class="ur-builder-message-container"></div>'
					);
					$message_container = $(".ur-form-container").find(
						".ur-builder-message-container"
					);
					$message_container.css({ top: $admin_bar.height() + "px" });
				}

				if ("success" === type) {
					message_string =
						'<div class="ur-message"><div class="ur-success"><p><strong>' +
						user_registration_form_builder_data.i18n_admin
							.i18n_success +
						"! </strong>" +
						message +
						'</p><span class="dashicons dashicons-no-alt ur-message-close"></span></div></div>';
				} else {
					$(".ur-error").remove();
					message_string =
						'<div class="ur-message"><div class="ur-error"><p><strong>' +
						user_registration_form_builder_data.i18n_admin
							.i18n_error +
						"! </strong>" +
						message +
						'</p><span class="dashicons dashicons-no-alt ur-message-close"></span></div></div>';
				}

				var $message = $(message_string).prependTo($message_container);
				setTimeout(function () {
					$message.addClass("entered");
				}, 50);

				if ($(".ur-error").find(".ur-captcha-error").length == 1) {
					$(".ur-error").css("width", "490px");
					setTimeout(function () {
						URFormBuilder.removeMessage($message);
					}, 5000);
				} else {
					setTimeout(function () {
						URFormBuilder.removeMessage($message);
					}, 3000);
				}
			},
			/**
			 * Remove the validation message when calles.
			 *
			 * @param string $message Validation message string.
			 */
			removeMessage: function ($message) {
				$message.removeClass("entered").addClass("exiting");
				setTimeout(function () {
					$message.remove();
				}, 120);
			},
			/**
			 * Get all the form data from form builder.
			 */
			get_form_data: function () {
				var form_data = [];
				var single_row = $(".ur-input-grids .ur-single-row");
				$.each(single_row, function () {
					var grid_list_item = $(this).find(".ur-grid-list-item");
					var single_row_data = [];
					$.each(grid_list_item, function () {
						var grid_item = $(this);
						var grid_wise_data =
							URFormBuilder.get_grid_wise_data(grid_item);
						single_row_data.push(grid_wise_data);
					});

					form_data.push(single_row_data);
				});
				return form_data;
			},
			/**
			 * Get all the form row data form builder.
			 */
			get_form_row_data: function () {
				var row_data = [];
				var single_row = $(".ur-input-grids .ur-single-row");
				$.each(single_row, function () {
					var single_row_data = {};
					var row_id = $(this).attr("data-row-id");

					if (
						$(
							".ur-individual-row-settings[data-row-id='" +
								row_id +
								"']"
						).length
					) {
						single_row_data.row_id = $(this).attr("data-row-id");

						var element = $(document).find(
								".ur-individual-row-settings[data-row-id='" +
									row_id +
									"']"
							),
							conditional_logic_enabled = element
								.find(
									"#user_registration_row_setting_enable_conditional_logic"
								)
								.is(":checked");

						if (element.find(".urcl-row-logic-wrap").length) {
							single_row_data.type = "normal";
							single_row_data.conditional_logic_enabled =
								conditional_logic_enabled;

							var $mapCreator = element.find(
									".urcl-row-logic-wrap"
								),
								rule = {
									action: $mapCreator
										.find(".urcl-row-field")
										.val(),
									logic_map: {
										type: "group",
										logic_gate: $mapCreator
											.find(".urcl-root-logic-gate")
											.val(),
										conditions: []
									}
								},
								sub_group_conditions = [],
								logic_map = null,
								cl_fields,
								logic_gate;

							$mapCreator
								.find(
									".urcl-row-conditional-logic-conditions-container"
								)
								.each(function () {
									cl_fields = [];
									logic_gate = $(this)
										.find(".urcl-logic-gate")
										.hasClass("is-active")
										? $(this)
												.find(
													".urcl-logic-gate.is-active"
												)
												.data("value")
										: "OR";

									$(this)
										.find(".urcl-field")
										.each(function () {
											cl_fields.push({
												type: "field",
												triggerer_id: $(this)
													.find(
														".urcl-field-conditional-field-select"
													)
													.val(),
												operator: $(this)
													.find(
														".urcl-select-operator"
													)
													.val(),
												value: $(this)
													.find(
														".urcl-row-field-value"
													)
													.val()
											});
										});

									sub_group_conditions.push({
										type: "group",
										logic_gate: logic_gate
											? logic_gate
											: "",
										conditions: cl_fields
									});
								});
							rule.logic_map.conditions = sub_group_conditions;
							logic_map = JSON.stringify(rule);
							single_row_data.cl_map = logic_map;
						}

						if (
							element
								.find(".ur-repeater-row-option")
								.attr("data-repeater-id")
						) {
							var repeater_id = element
								.find(".ur-repeater-row-option")
								.attr("data-repeater-id");
							single_row_data.type = "repeater";
							single_row_data["repeater_id"] = repeater_id;
							single_row_data["title"] = element
								.find(".ur-repeater-row-option")
								.find(
									"input[name='user_registration_repeater_row_title_" +
										repeater_id +
										"']"
								)
								.val();
							single_row_data["field_name"] = element
								.find(".ur-repeater-row-option")
								.find(
									"input[name='user_registration_repeater_row_field_name_" +
										repeater_id +
										"']"
								)
								.val();
							single_row_data["add_new_label"] = element
								.find(".ur-repeater-row-option")
								.find(
									"input[name='user_registration_repeater_row_add_new_label_" +
										repeater_id +
										"']"
								)
								.val();
							single_row_data["remove_label"] = element
								.find(".ur-repeater-row-option")
								.find(
									"input[name='user_registration_repeater_row_remove_label_" +
										repeater_id +
										"']"
								)
								.val();
							single_row_data["repeat_limit"] = element
								.find(".ur-repeater-row-option")
								.find(
									"input[name='user_registration_repeater_row_repeat_limit_" +
										repeater_id +
										"']"
								)
								.val();

							var fields = [];
							$(this)
								.find("input[data-field='field_name']")
								.each(function () {
									fields.push($(this).val());
								});

							single_row_data["fields"] = fields;
						}

						if (single_row_data) {
							row_data.push(single_row_data);
						}
					}
				});

				return row_data;
			},
			/**
			 * Get all the grid wise form data from form builder.
			 *
			 * @param Object $grid_item Contains information about grids in which the whole form has been divided into.
			 */
			get_grid_wise_data: function ($grid_item) {
				var all_field_item = $grid_item.find(".ur-selected-item");
				var all_field_data = [];
				$.each(all_field_item, function () {
					var $this_item = $(this);
					var field_key = $this_item
						.find(".ur-field")
						.attr("data-field-key");
					var field_id = "user_registration_" + field_key;
					var icon_class = $("li[data-field-id ='" + field_id + "']")
						.find(".ur-icon")
						.attr("class");
					var single_field_data = {
						field_key: field_key,
						general_setting:
							URFormBuilder.get_field_general_setting($this_item),
						advance_setting:
							URFormBuilder.get_field_advance_setting($this_item),
						icon: icon_class
					};

					all_field_data.push(single_field_data);
				});
				return all_field_data;
			},
			/**
			 * Get the general settings of a specific field in the grid.
			 *
			 * @param Object $single_item Contains information about each items in the grid.
			 */
			get_field_general_setting: function ($single_item) {
				var general_setting_field = $single_item
					.find(".ur-general-setting-block")
					.find(".ur-general-setting-field");
				var general_setting_data = {};

				var option_values = [];
				var default_values = [];
				var image_captcha_options = [];
				$.each(general_setting_field, function () {
					var is_checkbox = $(this)
						.closest(".ur-general-setting")
						.hasClass("ur-setting-checkbox");

					if ("options" === $(this).attr("data-field")) {
						if (
							"multiple_choice" ===
							$(this).attr("data-field-name")
						) {
							var li_elements = $(this).closest("ul").find("li");
							var array_value = [];

							li_elements.each(function (index, element) {
								var label = $(element)
									.find("input.ur-type-checkbox-label")
									.val();

								var value = $(element)
									.find("input.ur-type-checkbox-money-input")
									.val();
								var sell_value = $(element)
									.find(
										"input.ur-checkbox-selling-price-input"
									)
									.val();
								var image = $(element)
									.find("input.ur-type-image-choice")
									.val();
								if (
									array_value.every(function (each_value) {
										return each_value.label !== label;
									})
								) {
									general_setting_data["options"] =
										array_value.push({
											label: label,
											value: value,
											sell_value: sell_value,
											image: image
										});
								}
								general_setting_data["options"] = array_value;
							});
						} else if (
							"image-choice" === $(this).attr("data-field-name")
						) {
							var li_elements = $(this).closest("ul").find("li");
							var array_value = [];

							li_elements.each(function (index, element) {
								var label = $(element)
									.find(
										"input.ur-type-radio-label,input.ur-type-checkbox-label"
									)
									.val();
								var image = $(element)
									.find("input.ur-type-image-choice")
									.val();

								if (
									array_value.every(function (each_value) {
										return each_value.label !== label;
									})
								) {
									array_value.push({
										label: label,
										image: image
									});
								}
							});

							general_setting_data["image_options"] = array_value;

							var choice_value = URFormBuilder.get_ur_data(
								$(this)
							).trim();

							if (
								option_values.every(function (each_value) {
									return each_value !== choice_value;
								})
							) {
								general_setting_data["options"] =
									option_values.push(choice_value);
							}
							var filteredArray = option_values.filter(Boolean);
							general_setting_data["options"] = filteredArray;
						} else if (
							"subscription_plan" ===
							$(this).attr("data-field-name")
						) {
							var li_elements = $(this).closest("ul").find("li");
							var array_value = [];

							li_elements.each(function (index, element) {
								var label = $(element)
									.find("input.ur-type-radio-label")
									.val();

								var value = $(element)
									.find("input.ur-type-radio-money-input")
									.val();
								var sell_value = $(element)
									.find("input.ur-radio-selling-price-input")
									.val();
								var interval_count = $(element)
									.find("input.ur-radio-interval-count-input")
									.val();
								var recurring_period = $(element)
									.find(".ur-radio-recurring-period")
									.val();
								var trail_interval_count = $(element)
									.find(
										"input.ur-radio-trail-interval-count-input"
									)
									.val();
								var subscription_expiry_date = $(element)
									.find("input.ur-subscription-expiry-date")
									.val()
									.toString();
								var trail_recurring_period = $(element)
									.find(".ur-radio-trail-recurring-period")
									.val();

								var subscription_expiry_enable_value = $(
									element
								)
									.find(".ur-radio-enable-expiry-date")
									.val();

								var trail_period_enable = $(element)
									.find(".ur-radio-enable-trail-period")
									.val();

								if (
									array_value.every(function (each_value) {
										return each_value.label !== label;
									})
								) {
									array_value.push({
										label: label,
										value: value,
										sell_value: sell_value,
										interval_count: interval_count,
										recurring_period: recurring_period,
										trail_period_enable:
											trail_period_enable,
										trail_interval_count:
											trail_interval_count,
										trail_recurring_period:
											trail_recurring_period,
										subscription_expiry_date:
											subscription_expiry_date,
										subscription_expiry_enable:
											subscription_expiry_enable_value
									});
								}
								general_setting_data["options"] = array_value;
							});
						} else if (
							"captcha" === $(this).attr("data-field-name")
						) {
							var li_elements = $(this).closest("ul").find("li");
							var captcha_value = [];

							li_elements.each(function (index, element) {
								var question = $(element)
									.find("input.ur-type-captcha-question")
									.val();

								var answer = $(element)
									.find(".ur-type-captcha-answer")
									.val();
								if (
									captcha_value.every(function (each_value) {
										return each_value.question !== question;
									})
								) {
									general_setting_data["options"] =
										captcha_value.push({
											question: question,
											answer: answer
										});
								}
								general_setting_data["options"] = captcha_value;
							});
						} else {
							var choice_value = URFormBuilder.get_ur_data(
								$(this)
							).trim();
						}
						if (
							option_values.every(function (each_value) {
								return each_value !== choice_value;
							})
						) {
							general_setting_data["options"] =
								option_values.push(choice_value);
							general_setting_data["options"] = option_values;
						}
					} else {
						if ("default_value" === $(this).attr("data-field")) {
							if (is_checkbox === true) {
								if ($(this).is(":checked")) {
									general_setting_data["default_value"] =
										default_values.push(
											URFormBuilder.get_ur_data($(this))
										);
									general_setting_data["default_value"] =
										default_values;
								}
							} else if ($(this).is(":checked")) {
								general_setting_data["default_value"] =
									URFormBuilder.get_ur_data($(this));
							}
						} else if ("html" === $(this).attr("data-field")) {
							general_setting_data[$(this).attr("data-field")] =
								URFormBuilder.get_ur_data($(this)).replace(
									/"/g,
									"'"
								);
						} else if (
							"image_captcha_options" ===
							$(this).attr("data-field")
						) {
							var li_elements = $(this).closest("ul").find("li"),
								captcha_unique = $(this)
									.closest("ul")
									.attr("data-unique-captcha");
							var image_captcha_value = [];

							$.each(
								li_elements,
								function (li_index, li_element) {
									if (
										typeof image_captcha_options[
											li_index
										] !== "undefined" &&
										typeof image_captcha_options[li_index][
											"icon_tag"
										] !== "undefined" &&
										typeof image_captcha_options[li_index][
											"icon-2"
										] !== "undefined" &&
										typeof image_captcha_options[li_index][
											"icon-1"
										] !== "undefined" &&
										typeof image_captcha_options[li_index][
											"icon-3"
										] !== "undefined"
									) {
										return;
									}
									var icon_wraps = $(li_element)
										.find(".icons-group")
										.find(".icon-wrap");

									image_captcha_value["correct_icon"] = $(
										li_element
									)
										.find(
											'input[name="ur_general_setting[captcha_image][' +
												li_index +
												"][correct_icon][" +
												captcha_unique +
												']"]:checked'
										)
										.val();
									image_captcha_value["icon_tag"] = $(
										li_element
									)
										.find(
											'input[name="ur_general_setting[captcha_image][' +
												li_index +
												'][icon_tag]"]'
										)
										.val();

									$.each(
										icon_wraps,
										function (icon_index, icon_wrap) {
											var next_icon_index =
												icon_index + 1;

											image_captcha_value[
												"icon-" + next_icon_index
											] = $(icon_wrap)
												.find(
													'input:hidden[name="ur_general_setting[captcha_image][' +
														li_index +
														"][icon-" +
														next_icon_index +
														']"]'
												)
												.val();
										}
									);

									image_captcha_options.push({
										"icon-1": image_captcha_value["icon-1"],
										"icon-2": image_captcha_value["icon-2"],
										"icon-3": image_captcha_value["icon-3"],
										icon_tag:
											image_captcha_value["icon_tag"],
										correct_icon:
											image_captcha_value["correct_icon"]
									});
								}
							);
							general_setting_data["image_captcha_options"] =
								image_captcha_options;
						} else {
							general_setting_data[$(this).attr("data-field")] =
								URFormBuilder.get_ur_data($(this));
						}
					}
				});
				// For membership field: read membership_active_memberships from live #ur-setting-form so multiselect value is saved
				var field_key = $single_item
					.find(".ur-field")
					.attr("data-field-key");
				if (
					field_key === "membership" &&
					$single_item.hasClass("ur-item-active")
				) {
					var $activeSelect = $(
						"#ur-setting-form .ur-general-setting-membership_active_memberships select"
					);
					if ($activeSelect.length) {
						var liveVal = $activeSelect.val();
						general_setting_data.membership_active_memberships =
							liveVal != null && Array.isArray(liveVal)
								? liveVal
								: liveVal
								? [].concat(liveVal)
								: [];
					}
					var $showBankToggle = $(
						"#ur-setting-form .ur-general-setting-user_registration_show_bank_details_on_form input.ur-general-setting-field"
					);
					if ($showBankToggle.length) {
						general_setting_data.user_registration_show_bank_details_on_form =
							$showBankToggle.is(":checked");
						if (
							!general_setting_data.user_registration_show_bank_details_on_form
						) {
							general_setting_data.user_registration_show_bank_details_on_form =
								"false";
						}
					}
				}
				return general_setting_data;
			},
			/**
			 * Get the advance settings of a specific field in the grid.
			 *
			 * @param Object $single_item Contains information about each items in the grid.
			 */
			get_field_advance_setting: function ($single_item) {
				var advance_setting_field = $single_item
					.find(".ur-advance-setting-block")
					.find(".ur_advance_setting");
				var advance_setting_data = {};
				$.each(advance_setting_field, function () {
					advance_setting_data[$(this).attr("data-advance-field")] =
						URFormBuilder.get_ur_data($(this));
				});
				return advance_setting_data;
			},
			/**
			 * Get the datas of each nodes in the field settings.
			 *
			 * @param Object $this_node Specific nodes for each settings.
			 */
			get_ur_data: function ($this_node) {
				var node_type = $this_node.get(0).tagName.toLowerCase();
				var value = "";
				switch (node_type) {
					case "input":
						// Check input type.
						switch ($this_node.attr("type")) {
							case "checkbox":
								value = $this_node.is(":checked");

								if (
									$this_node.hasClass(
										"ur-type-checkbox-value"
									)
								) {
									value = $this_node.val();
								}

								if (
									$this_node.hasClass("ur-type-toggle") &&
									!value
								) {
									value = "false";
								}
								break;

							default:
								if (
									!$this_node.hasClass(
										"ur-type-image-choice"
									) &&
									!$this_node.hasClass(
										"ur-subscription-expiry-date"
									)
								) {
									value = $this_node.val();
								}
								break;
						}

						break;
					case "select":
						value = $this_node.val();
						break;
					case "textarea":
						value = $this_node.val();
						break;
					default:
				}

				return value;
			},
			/**
			 * Get all form row ids.
			 */
			get_form_row_ids: function () {
				var row_ids = [];
				var single_row = $(".ur-input-grids .ur-single-row");
				$.each(single_row, function () {
					row_ids.push($(this).attr("data-row-id"));
				});
				return row_ids;
			},
			/**
			 * Parse integer value from string.
			 *
			 * @param string Parse integer value form a string.
			 */
			ur_parse_int: function (value) {
				return parseInt(value, 0);
			},
			/**
			 * Rounds a number up to the next largest integer.
			 */
			ur_math_ceil: function (value) {
				return Math.ceil(value, 0);
			},
			/**
			 * Get all the data related to form_restriction
			 */
			get_form_restriction_submit_data: function () {
				var form_data = $(".urfr-qna-block")
					.map(function (k, item) {
						return {
							question: $(item)
								.find('input[name="urfr_qna_question"]')
								.val(),
							answer: $(item)
								.find('input[name="urfr_qna_answer"]')
								.val()
						};
					})
					.get();
				return JSON.stringify(form_data);
			},
			/**
			 * Get all the data related to calculations
			 */
			get_form_calculation_data: function () {
				var form_data = [];
				var single_row = $(".urcal-container");
				$.each(single_row, function () {
					var field_name = $(this)
							.attr("id")
							.replace("urcal-container-", ""),
						enable_calculation_field = $(this)
							.siblings(".ur-advance-enable_calculations")
							.find(".ur-enable-calculations"),
						decimal_places_field = $(this).find(
							"input.ur-calculation-decimal-places"
						),
						calculation_formula_field = $(this).find(
							'[data-field-id="ur-calculation-field-' +
								field_name +
								'-editor"]'
						);
					var calculation_data = {
						field_name: field_name,
						enable_calculations:
							enable_calculation_field.is(":checked"),
						decimal_places_field: decimal_places_field.val(),
						calculation_field: calculation_formula_field.val()
					};
					form_data.push(calculation_data);
				});

				return form_data;
			},
			/**
			 * Get all the conditions datas that the user has set in conditionally assign user role settings.
			 */
			get_form_conditional_role_data: function () {
				var form_data = [];
				var single_row = $(".urcl-role-logic-wrap");

				$.each(single_row, function () {
					var grid_list_item = $(this).find(".urcl-user-role-field");
					var all_field_data = [];
					var or_field_data = [];
					var assign_role = "";
					$.each(grid_list_item, function () {
						$field_key = $(this).attr("name").split("[");

						if (
							"user_registration_form_conditional_user_role" ===
							$field_key[0]
						) {
							assign_role = $(this).val();
							grid_list_item.splice($(this), 1);
						}
					});

					var conditional_group = $(this).find(
						".urcl-conditional-group"
					);
					$.each(conditional_group, function () {
						var inner_conditions = [];
						var grid_list_item = $(this).find(
							".urcl-user-role-field"
						);
						$.each(grid_list_item, function () {
							var conditions = {
								field_key: $(this).attr("name"),
								field_value: $(this).val()
							};
							inner_conditions.push(conditions);
						});
						all_field_data.push(inner_conditions);
					});

					var or_groups = $(this).find(".urcl-or-groups");
					$.each(or_groups, function () {
						var conditional_or_group = $(this).find(
							".urcl-conditional-or-group"
						);
						var or_data = [];
						$.each(conditional_or_group, function () {
							var inner_or_conditions = [];
							var or_list_item = $(this).find(
								".urcl-user-role-field"
							);
							$.each(or_list_item, function () {
								var or_conditions = {
									field_key: $(this).attr("name"),
									field_value: $(this).val()
								};
								inner_or_conditions.push(or_conditions);
							});
							or_data.push(inner_or_conditions);
						});
						or_field_data.push(or_data);
					});
					var all_fields = {
						assign_role: assign_role,
						conditions: all_field_data,
						or_conditions: or_field_data
					};
					form_data.push(all_fields);
				});
				return form_data;
			},
			/**
			 * Get all the conditions data for conditional logic settings for submit button.
			 */
			get_form_conditional_submit_data: function () {
				var form_data = [];
				var single_row = $(".urcl-submit-logic-wrap");

				$.each(single_row, function () {
					var grid_list_item = $(this).find(".urcl-submit-field");
					var all_field_data = [];
					var or_field_data = [];
					var action = "";
					$.each(grid_list_item, function () {
						$field_key = $(this).attr("name").split("[");

						if (
							"user_registration_form_conditional_submit" ===
							$field_key[0]
						) {
							action = $(this).val();
							grid_list_item.splice($(this), 1);
						}
					});

					var conditional_group = $(this).find(
						".urcl-conditional-group"
					);
					$.each(conditional_group, function () {
						var inner_conditions = [];
						var grid_list_item = $(this).find(".urcl-submit-field");
						$.each(grid_list_item, function () {
							var conditions = {
								field_key: $(this).attr("name"),
								field_value: $(this).val()
							};
							inner_conditions.push(conditions);
						});
						all_field_data.push(inner_conditions);
					});

					var or_groups = $(this).find(".urcl-or-groups");
					$.each(or_groups, function () {
						var conditional_or_group = $(this).find(
							".urcl-conditional-or-group"
						);
						var or_data = [];
						$.each(conditional_or_group, function () {
							var inner_or_conditions = [];
							var or_list_item =
								$(this).find(".urcl-submit-field");
							$.each(or_list_item, function () {
								var or_conditions = {
									field_key: $(this).attr("name"),
									field_value: $(this).val()
								};
								inner_or_conditions.push(or_conditions);
							});
							or_data.push(inner_or_conditions);
						});
						or_field_data.push(or_data);
					});
					var all_fields = {
						action: action,
						conditions: all_field_data,
						or_conditions: or_field_data
					};

					form_data.push(all_fields);
				});
				return form_data;
			},
			/**
			 * Get all the overrided email contents saved by the user.
			 */
			get_form_email_content_override_data: function () {
				var specific_email_contents = {};
				var single_row = $(
					".user-registration-email-template-content-wrap"
				);

				$.each(single_row, function () {
					var email_title_item = $(this).find(
						".user-registration-card__header"
					);
					var email_body_item = $(this).find(
						".user-registration-card__body"
					);

					specific_email_contents[$(this).prop("id")] = {
						title: email_title_item
							.find(".user-registration-card__title ")
							.text(),
						description: email_title_item
							.find(".user-registration-help-tip")
							.data("description"),
						override: email_title_item
							.find("#uret_override_" + $(this).prop("id"))
							.hasClass("enabled")
							? 1
							: 0,
						subject: email_body_item
							.find(".uret_subject_input")
							.val(),
						content: email_body_item
							.find(
								"#user_registration_" +
									$(this).prop("id") +
									"_content"
							)
							.val()
					};
				});

				return specific_email_contents;
			},
			/**
			 * Handles all the actions performed inside form builder.
			 */
			init_form_builder: function () {
				$.fn.ur_form_builder = function () {
					var loaded_params = {
						active_grid:
							user_registration_form_builder_data.active_grid,
						number_of_grid_list:
							user_registration_form_builder_data.number_of_grid,
						min_grid_height: 70
					};
					// traverse all nodes
					return this.each(function () {
						// express a single node as a jQuery object
						var $this = $(this);
						var builder = {
							init: function () {
								this.single_row();
								this.manage_required_fields();
								this.manage_label_hidden_fields();
								this.manage_image_choice_class();
								this.manage_state_fields();
							},
							single_row: function () {
								if (
									!user_registration_form_builder_data.is_edit_form
								) {
									var single_row = $(
										"<div class='ur-single-row'/ data-row-id=\"0\">"
									);
									single_row.append(
										$("<div class='ur-grids'/>")
									);
									var grid_button = this.get_grid_button();
									single_row
										.find(".ur-grids")
										.append(grid_button);
									single_row
										.find(".ur-grids")
										.find(
											'span[data-id="' +
												loaded_params.active_grid +
												'"]'
										)
										.addClass("ur-active-grid");
									var grid_list = this.get_grid_lists(
										loaded_params.active_grid
									);
									single_row.append(
										'<div style="clear:both"></div>'
									);
									single_row.append(grid_list);
									single_row.append(
										'<div style="clear:both"></div>'
									);

									$this.append(single_row);
									$(".ur-single-row")
										.eq(0)
										.find(".ur-grid-lists")
										.eq(0)
										.find(".ur-grid-list-item")
										.eq(0)
										.find(".user-registration-dragged-me")
										.remove();
									$(".ur-single-row")
										.eq(0)
										.find(".ur-grid-lists")
										.eq(0)
										.find(".ur-grid-list-item")
										.eq(0)
										.append(
											user_registration_form_builder_data.required_form_html
										);
								}

								if ($this.find(".ur-add-new-row").length == 0) {
									$this.append(
										'<button type="button" class="button button-primary dashicons dashicons-plus-alt ur-add-new-row ui-sortable-handle">' +
											user_registration_form_builder_data.add_new +
											"</button>"
									);
									var total_rows = $this
										.find(".ur-add-new-row")
										.siblings(".ur-single-row")
										.last()
										.prev()
										.attr("data-row-id");
									$this
										.find(".ur-row-buttons")
										.attr("data-total-rows", total_rows);
								}
								events.render_draggable_sortable();
								builder.manage_empty_grid();
								builder.manage_draggable_users_fields();
							},
							/**
							 * Structure for grid buttons.
							 */
							get_grid_button: function () {
								var grid_button = $(
									'<div class="ur-grid-containner"/>'
								);
								var grid_content =
									'<button type="button" class="ur-edit-grid"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M28,6V26H4V6H28m2-2H2V28H30V4Z"/></svg></button>';
								grid_content +=
									'<button type="button" class="dashicons dashicons-no-alt ur-remove-row"></button>';
								grid_content +=
									'<div class="ur-toggle-grid-content" style="display:none">';
								grid_content +=
									"<small>Select the grid column.</small>";
								grid_content +=
									'<div class="ur-grid-selector" data-grid = "1">';
								grid_content +=
									'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M28,6V26H4V6H28m2-2H2V28H30V4Z"/></svg>';
								grid_content += "</div>";
								grid_content +=
									'<div class="ur-grid-selector" data-grid = "2">';
								grid_content +=
									'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M17,4H2V28H30V4ZM4,26V6H15V26Zm24,0H17V6H28Z"/></svg>';
								grid_content += "</div>";
								grid_content +=
									'<div class="ur-grid-selector" data-grid = "3">';
								grid_content +=
									'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M22,4H2V28H30V4ZM4,26V6h6V26Zm8,0V6h8V26Zm16,0H22V6h6Z"/></svg>';
								grid_content += "</div>";
								grid_content += "</div>";
								grid_button.html(grid_content);
								return grid_button.html();
							},
							/**
							 * Structure for grid lists.
							 *
							 * @param integer number_of_grid Number of grids in the form.
							 */
							get_grid_lists: function (number_of_grid) {
								var grid_lists = $(
									'<div class="ur-grid-lists"/>'
								);
								for (var i = 1; i <= number_of_grid; i++) {
									var grid_list_item = $(
										"<div ur-grid-id='" +
											i +
											"' class='ur-grid-list-item'></div>"
									);
									var width =
										Math.floor(100 / number_of_grid) -
										number_of_grid;
									grid_list_item.css({
										width: width + "%",
										"min-height":
											loaded_params.min_grid_height + "px"
									});
									grid_lists.append(grid_list_item);
								}
								grid_lists.append(
									'<div style="clear:both"></div>'
								);
								grid_lists
									.find(".ur-grid-list-item")
									.eq("0")
									.css({});
								return grid_lists;
							},
							/**
							 * Hides label of fields if hide label option is enabled.
							 */
							manage_label_hidden_fields: function () {
								$('input[data-field="hide_label"]').each(
									function () {
										if ($(this).is(":checked")) {
											$(this)
												.closest(".ur-selected-item")
												.find(".ur-label")
												.find("label")
												.hide();
										} else {
											$(this)
												.closest(".ur-selected-item")
												.find(".ur-label")
												.find("label")
												.show();
										}
									}
								);
							},
							/**
							 * toggleclass if image choice option is enabled.
							 */
							manage_image_choice_class: function () {
								$('input[data-field="image_choice"]').each(
									function () {
										if ($(this).is(":checked")) {
											$(this)
												.closest(".ur-selected-item")
												.find(".ur-admin-template")
												.find(".ur-field")
												.addClass(
													"user-registration-image-options"
												);
										} else {
											$(this)
												.closest(".ur-selected-item")
												.find(".ur-admin-template")
												.find(".ur-field")
												.removeClass(
													"user-registration-image-options"
												);
										}
									}
								);
							},
							/**
							 * Information about required fields
							 */
							manage_required_fields: function () {
								var required_fields =
									user_registration_form_builder_data.form_required_fields;

								var selected_inputs = $(".ur-input-grids");

								if (Array.isArray(required_fields)) {
									for (
										var i = 0;
										i < required_fields.length;
										i++
									) {
										var field_node = selected_inputs.find(
											'.ur-field[data-field-key="' +
												required_fields[i] +
												'"]'
										);

										field_node
											.closest(".ur-selected-item")
											.find(
												'input[data-field="required"]'
											)
											.trigger("change");
										field_node
											.closest(".ur-selected-item")
											.find(
												'input[data-field="required"]'
											)
											.attr("disabled", "disabled");
									}
								}

								selected_inputs
									.find(".ur-selected-item")
									.each(function () {
										if (
											$(this)
												.find(
													'input[data-field="required"]'
												)
												.is(":checked")
										) {
											var label_node = $(this)
												.find(".ur-label")
												.find("label");
											label_node
												.find("span:contains('*')")
												.remove();
											label_node.append(
												'<span style="color:red">*</span>'
											);
										}
									});
							},
							/**
							 * Structure for empty grid.
							 */
							manage_empty_grid: function () {
								var main_containner = $(".ur-input-grids");
								var drag_me = $(
									'<div class="user-registration-dragged-me"/>'
								);
								main_containner
									.find(".user-registration-dragged-me")
									.remove();
								$.each(
									main_containner.find(".ur-grid-list-item"),
									function () {
										var $this = $(this);
										if (
											$(this).find(".ur-selected-item")
												.length === 0
										) {
											$this.append(drag_me.clone());
										}
									}
								);
							},
							/**
							 * Manage draggable user fields.
							 */
							manage_draggable_users_fields: function () {
								var single_draggable_fields =
									user_registration_form_builder_data.form_one_time_draggable_fields;

								var ul_node = $(
									"#ur-tab-registered-fields"
								).find("ul.ur-registered-list");

								//-> Disable payment fields from dragging when membership field is present.
								var payment_nodes = [];
								$.each(
									user_registration_form_builder_data.form_payment_fields,
									function (index, identifier) {
										var selector =
											"#user_registration_" +
											identifier +
											"_list";
										payment_nodes.push($(selector));
									}
								);
								$.each(payment_nodes, function () {
									var $this = $(this);
									var has_membership_field =
										$(".ur-input-grids").find(
											'.ur-field[data-field-key="membership"]'
										).length > 0;
									if (has_membership_field) {
										$this.draggable("disable");
										$this.addClass("ur-locked-field");
										$this.addClass(
											"ur-membership-payment-field-disabled"
										);
									}
								});
								// Disable membership field from dragging when payment setting is enabled already.
								var $checkboxes = $(
									"[data-field-group='payments'] input[name^='user_registration_enable_']"
								);
								$membershipField = $(
									".ur-registered-list"
								).find(
									"li[data-field-id='user_registration_membership']"
								);
								if ($checkboxes.is(":checked")) {
									$membershipField.draggable("disable");
									$membershipField.addClass(
										"ur-membership-field-disabled"
									);
									$membershipField.addClass(
										"ur-locked-field"
									);
									$membershipField.removeClass(
										"ur-no-membership-available"
									);
								} else if (
									typeof user_registration_form_builder_data.form_has_membership_available !==
										"undefined" &&
									!user_registration_form_builder_data.form_has_membership_available
								) {
									$membershipField.draggable("disable");
									$membershipField.addClass(
										"ur-no-membership-available"
									);
									$membershipField.addClass(
										"ur-locked-field"
									);
									$membershipField.removeClass(
										"ur-membership-field-disabled"
									);
								} else {
									// enable membership field.
									$membershipField.draggable("enable");
									$membershipField.removeClass(
										"ur-membership-field-disabled"
									);
									$membershipField.removeClass(
										"ur-no-membership-available"
									);
									$membershipField.removeClass(
										"ur-locked-field"
									);
								}

								$.each(ul_node.find("li"), function () {
									var $this = $(this);

									var data_field_id = $(this)
										.attr("data-field-id")
										.replace("user_registration_", "");

									if (
										$.inArray(
											data_field_id,
											single_draggable_fields
										) >= 0 &&
										(!$this.hasClass("ur-locked-field") ||
											($this.hasClass(
												"ur-locked-field"
											) &&
												$this.hasClass(
													"ur-one-time-draggable-disabled"
												)))
									) {
										if (
											$(".ur-input-grids").find(
												'.ur-field[data-field-key="' +
													data_field_id +
													'"]'
											).length > 0
										) {
											$this.draggable("disable");
											$this.addClass("ur-locked-field");
											$this.addClass(
												"ur-one-time-draggable-disabled"
											);
										} else {
											$this.draggable("enable");
											$this.removeClass(
												"ur-locked-field"
											);
											$this.removeClass(
												"ur-one-time-draggable-disabled"
											);
										}
									}
								});

								var locked = ul_node.find(".ur-locked-field");
								$.each(locked, function () {
									$this = $(this);
									$this.draggable("disable");
								});
							},
							/**
							 * Populate the dropped node when a field is dragged from field container to form builder area.
							 */
							populate_dropped_node: function (
								container,
								form_field_id
							) {
								var form_id = $("#ur_form_id").val();
								var data = {
									action: "user_registration_user_input_dropped",
									security:
										user_registration_form_builder_data.user_input_dropped,
									form_field_id: form_field_id,
									form_id: form_id
								};

								var template_text =
									'<div class="ur-selected-item ajax_added"><div class="ur-action-buttons">' +
									'<span title="Clone" class="dashicons dashicons-admin-page ur-clone"></span>' +
									'<span title="Trash" class="dashicons dashicons-trash ur-trash"></span>' +
									"</div>(content)</div>";
								container
									.closest(".ur-single-row")
									.find(".user-registration-dragged-me")
									.fadeOut();
								$.ajax({
									url: user_registration_form_builder_data.ajax_url,
									data: data,
									type: "POST",
									beforeSend: function () {
										container
											.removeAttr("class")
											.removeAttr("id")
											.removeAttr("data-field-id")
											.addClass("ur-selected-item")
											.css({ width: "auto" });
										container.html(
											'<small class="spinner is-active"></small>'
										);
										container.addClass("ur-item-dragged");
									},
									complete: function (response) {
										builder.manage_empty_grid();

										if (
											response.responseJSON.success ===
											true
										) {
											var template = $(
												template_text.replace(
													"(content)",
													response.responseJSON.data
														.template
												)
											);
											template.removeClass("ajax_added");
											template.removeClass(
												"ur-item-dragged"
											);
											container
												.find(".ajax_added")
												.find(".spinner")
												.remove();
											container
												.find(".ajax_added")
												.remove();

											$(template).insertBefore(container);
											container.remove();
										}
										builder.manage_draggable_users_fields();

										var populated_item = template
											.closest(".ur-selected-item ")
											.find("[data-field='field_name']")
											.val();
										URFormBuilder.manage_conditional_field_options(
											populated_item
										);

										$(
											'.ur-input-type-select2 .ur-field[data-field-key="select2"] select, .ur-input-type-multi-select2 .ur-field[data-field-key="multi_select2"] select'
										).selectWoo();

										var $template = $(template);

										// Get fieldKey from data-field-key attribute.
										var fieldKey = $template
											.find(".ur-field")
											.data("field-key");

										// Get field name.
										var fieldName = $template
											.find(
												'.ur-general-setting.ur-general-setting-field-name input[name="ur_general_setting[field_name]"]'
											)
											.val();

										// Get label text from label tag, excluding any span tags
										var label = $template
											.find(".ur-label label")
											.contents()
											.filter(function () {
												return this.nodeType === 3; // Filter out non-text nodes (e.g. <span> tags)
											})
											.text()
											.trim();

										// Get the visibility of the field.
										var visibleTo = $template
											.find(
												'select.ur_advance_setting.ur-settings-field-visibility[name="' +
													fieldKey +
													'_advance_setting[field_visibility]"]'
											)
											.val();
										$(document.body).trigger(
											"ur_new_field_created",
											[
												{
													fieldKey: fieldKey,
													fieldName: fieldName,
													label: label,
													visibleTo: visibleTo
												}
											]
										);
									}
								}).fail(function () {
									URFormBuilder.show_message(
										user_registration_form_builder_data.ajax_form_submit_error_on_field_drag,
										"error"
									);
									return;
								});
							},
							manage_state_fields: function () {
								$(
									'input[data-advance-field="enable_state"]'
								).each(function () {
									if ($(this).is(":checked")) {
										$(this)
											.closest(".ur-selected-item")
											.find(".ur-state-container-wrapper")
											.show();
									} else {
										$(this)
											.closest(".ur-selected-item")
											.find(".ur-state-container-wrapper")
											.hide();
									}
								});
							}
						};
						var events = {
							register: function () {
								this.register_add_new_row();
								this.register_remove_row();
								this.change_ur_grids();
								this.remove_selected_item();
								this.clone_selected_item();
							},
							register_add_new_row: function () {
								var $this_obj = this;
								$("body").on(
									"click",
									".ur-add-new-row",
									function () {
										var total_rows = $(this)
											.closest(".ur-row-buttons")
											.attr("data-total-rows");
										total_rows =
											"undefined" !== typeof total_rows
												? parseInt(total_rows)
												: 0;
										$(this)
											.closest(".ur-row-buttons")
											.attr(
												"data-total-rows",
												total_rows + 1
											);

										var single_row_clone = $(this)
											.closest(".ur-input-grids")
											.find(".ur-single-row")
											.eq(0)
											.clone();
										single_row_clone.attr(
											"data-row-id",
											total_rows + 1
										);
										single_row_clone
											.find(".ur-grid-lists")
											.html("");
										single_row_clone
											.find(".ur-grids")
											.find("span")
											.removeClass("ur-active-grid");
										single_row_clone
											.find(".ur-grids")
											.find(
												'span[data-id="' +
													loaded_params.active_grid +
													'"]'
											)
											.addClass("ur-active-grid");
										var grid_list = builder.get_grid_lists(
											loaded_params.active_grid
										);
										single_row_clone
											.find(".ur-grid-lists")
											.append(grid_list.html());
										single_row_clone.insertBefore(
											".ur-row-buttons"
										);
										single_row_clone.show();
										$this_obj.render_draggable_sortable();
										builder.manage_empty_grid();

										if (
											$(this).hasClass(
												"ur-add-repeater-row"
											)
										) {
											single_row_clone.addClass(
												"ur-repeater-row"
											);

											var repeater_count = $(this)
													.closest(".ur-input-grids")
													.find(
														".ur-repeater-row"
													).length,
												repeater_div =
													'<div class="ur-repeater-label"  id="user_registration_repeater_row_title_' +
													repeater_count +
													'"><label>Repeater Row</label></div>';
											single_row_clone.attr(
												"data-repeater-id",
												repeater_count
											);
											$(repeater_div).insertBefore(
												single_row_clone.find(
													".ur-grid-lists"
												)
											);
										}
										var form_id = $("#ur_form_id").val(),
											row_id =
												$(single_row_clone).attr(
													"data-row-id"
												);

										var data = {
											action: "user_registration_generate_row_settings",
											security:
												user_registration_form_builder_data.ur_new_row_added,
											form_id: form_id,
											row_id: row_id
										};

										$.ajax({
											url: user_registration_form_builder_data.ajax_url,
											data: data,
											type: "POST",
											complete: function (response) {
												if (
													response.responseJSON
														.success === true
												) {
													var settings_div =
														response.responseJSON
															.data;
													$(
														"form#ur-row-settings"
													).append(settings_div);

													$(
														".ur-individual-row-settings"
													).each(function () {
														if (
															$(this).attr(
																"data-row-id"
															) === row_id
														) {
															$(this).show();
														} else {
															$(this).hide();
														}
													});
													$(single_row_clone).trigger(
														"click"
													);
												}
											}
										});

										$(document).trigger(
											"user_registration_row_added",
											[single_row_clone]
										);
									}
								);
							},
							register_remove_row: function () {
								var $this = this;
								$("body").on(
									"click",
									".ur-remove-row",
									function () {
										var $this_row =
											$(this).closest(".ur-single-row");
										var fieldKeys = [];
										var fieldNames = [];
										$this_row
											.find(".ur-selected-item .ur-field")
											.each(function () {
												var fieldKey =
													$(this).data("field-key");
												var fieldName = $(this)
													.closest(
														".ur-selected-item"
													)
													.find(
														'input[data-field="field_name"]'
													)
													.val();
												var fieldLabel = $(this)
													.closest(
														".ur-selected-item"
													)
													.find(
														'.ur-general-setting-label input[data-field="label"]'
													)
													.val();
												fieldKeys.push(fieldKey);
												var field_data = {
													fieldName: fieldName,
													fieldLabel: fieldLabel
												};
												fieldNames.push(field_data);
											});

										if (
											fieldKeys.includes("user_pass") &&
											fieldKeys.includes("user_email")
										) {
											show_feature_notice("", "");
											return;
										} else if (
											fieldKeys.includes("user_pass")
										) {
											show_feature_notice(
												"user_pass",
												""
											);
											return;
										} else if (
											fieldKeys.includes("user_email")
										) {
											show_feature_notice(
												"user_email",
												""
											);
											return;
										}

										var data = {
											delete_item: true,
											fields: fieldNames
										};

										$(document).trigger(
											"user_registration_before_admin_row_remove",
											[data]
										);

										if (
											$(".ur-input-grids").find(
												".ur-single-row:visible"
											).length > 1
										) {
											if (data.delete_item) {
												ur_confirmation(
													user_registration_form_builder_data
														.i18n_admin
														.i18n_are_you_sure_want_to_delete_row,
													{
														title: user_registration_form_builder_data
															.i18n_admin
															.i18n_msg_delete,
														confirm: function () {
															var btn =
																$this_row.prev();
															var new_btn;
															if (
																btn.hasClass(
																	"ur-add-new-row"
																)
															) {
																new_btn =
																	btn.clone();
															} else {
																new_btn =
																	$this_row
																		.clone()
																		.attr(
																			"class",
																			"dashicons-minus ur-remove-row"
																		);
															}
															if (
																new_btn.hasClass(
																	"ur-add-new-row"
																)
															) {
																$this_row
																	.closest(
																		".ur-single-row"
																	)
																	.prev()
																	.find(
																		".ur-remove-row"
																	)
																	.before(
																		new_btn
																	);
															}
															var single_row =
																$this_row.closest(
																	".ur-single-row"
																);
															$(document).trigger(
																"user_registration_row_deleted",
																[single_row]
															);

															// Remove Row Fields from Conditional Select Dropdown.
															var row_fields =
																single_row.find(
																	".ur-grid-lists .ur-selected-item .ur-general-setting"
																);
															$(row_fields).each(
																function () {
																	var field_label =
																		$(this)
																			.closest(
																				".ur-selected-item"
																			)
																			.find(
																				" .ur-admin-template .ur-label label"
																			)
																			.text();
																	var field_key =
																		$(this)
																			.closest(
																				".ur-selected-item"
																			)
																			.find(
																				" .ur-admin-template .ur-field"
																			)
																			.data(
																				"field-key"
																			);

																	//strip certain fields
																	if (
																		"section_title" ==
																			field_key ||
																		"html" ==
																			field_key ||
																		"wysiwyg" ==
																			field_key ||
																		"billing_address_title" ==
																			field_key ||
																		"shipping_address_title" ==
																			field_key
																	) {
																		return;
																	}

																	var field_name =
																		$(this)
																			.find(
																				"[data-field='field_name']"
																			)
																			.val();

																	if (
																		typeof field_name !==
																		"undefined"
																	) {
																		// Remove item from conditional logic options
																		$(
																			'[class*="urcl-settings-rules_field_"] option[value="' +
																				field_name +
																				'"]'
																		).remove();

																		// Remove Field from Form Setting Conditionally Assign User Role.
																		$(
																			'[class*="urcl-field-conditional-field-select"] option[value="' +
																				field_name +
																				'"]'
																		).remove();

																		// Remove Field from Form Setting Default Phone field for SMS Verification.
																		$(
																			'[id="user_registration_form_setting_default_phone_field"] option[value="' +
																				field_name +
																				'"]'
																		).remove();
																	}
																}
															);
															single_row.remove();
															$this.check_grid();
															builder.manage_draggable_users_fields();

															Swal.fire({
																icon: "success",
																title: "Successfully deleted!",
																customClass:
																	"user-registration-swal2-modal user-registration-swal2-modal--center user-registration-swal2-no-button",
																showConfirmButton: false,
																timer: 1000
															});
														},
														reject: function () {
															// Do Nothing.
														}
													}
												);
											}
										} else {
											URFormBuilder.ur_alert(
												user_registration_form_builder_data
													.i18n_admin
													.i18n_at_least_one_row_is_required_to_create_a_registration_form,
												{
													title: user_registration_form_builder_data
														.i18n_admin
														.i18n_cannot_delete_row
												}
											);
										}
									}
								);
							},
							change_ur_grids: function () {
								var $this_obj = this;

								$(document).on(
									"click",
									".ur-grids .ur-edit-grid",
									function (e) {
										e.stopPropagation();
										$(this)
											.siblings(".ur-toggle-grid-content")
											.stop(true)
											.slideToggle(200);
									}
								);
								$(document).on("click", function () {
									$(".ur-toggle-grid-content")
										.stop(true)
										.slideUp(200);
								});

								$(document).on(
									"click",
									".ur-grids .ur-toggle-grid-content .ur-grid-selector",
									function () {
										var $this_single_row =
												$(this).closest(
													".ur-single-row"
												),
											grid_num =
												$(this).attr("data-grid"),
											grid_comp = $this_single_row.find(
												".ur-grid-lists .ur-grid-list-item"
											).length,
											$grids =
												builder.get_grid_lists(
													grid_num
												),
											iterator = 0;

										// Prevent from selecting same grid.
										if (
											$this_single_row.find(
												".ur-grid-lists .ur-grid-list-item"
											).length === parseInt(grid_num)
										) {
											return;
										}

										$this_single_row
											.find("button.ur-edit-grid")
											.html($(this).html());

										$.each(
											$this_single_row.find(
												".ur-grid-lists .ur-grid-list-item"
											),
											function () {
												$(this)
													.children("*")
													.each(function () {
														$grids
															.find(
																".ur-grid-list-item"
															)
															.eq(iterator)
															.append(
																$(this).clone()
															); // "this" is the current element in the loop.

														// In case the fields have to be redistributed into 2 columns - prioritizes left column first, if 3rd column is going away.
														if (
															3 ===
																parseInt(
																	$(this)
																		.parent()
																		.attr(
																			"ur-grid-id"
																		)
																) &&
															3 ===
																parseInt(
																	grid_comp
																) &&
															2 ===
																parseInt(
																	grid_num
																)
														) {
															iterator = Math.abs(
																--iterator
															); // Alternates between 0 and 1.
														}
													});

												// Decides to check if it's trying to push into lower amount of columns.
												// If so, it simply resets the index to 0 to disallow elements from removed rows.
												if (
													parseInt(grid_num) >
														grid_comp ||
													($(this).children("*")
														.length &&
														2 <= parseInt(grid_num))
												) {
													iterator =
														parseInt(grid_num) <=
														++iterator
															? 0
															: iterator;
												}
											}
										);

										$this_single_row
											.find(".ur-grid-lists")
											.eq(0)
											.hide();
										$grids
											.clone()
											.insertAfter(
												$this_single_row.find(
													".ur-grid-lists"
												)
											);
										$this_single_row
											.find(".ur-grid-lists")
											.eq(0)
											.remove();
										$this_obj.render_draggable_sortable();
										builder.manage_empty_grid();
									}
								);
							},
							render_draggable_sortable: function () {
								$(".ur-grid-list-item")
									.sortable({
										containment: ".ur-input-grids",
										over: function () {
											$(this).addClass(
												"ur-sortable-active"
											);
											builder.manage_empty_grid();
										},
										out: function () {
											$(this).removeClass(
												"ur-sortable-active"
											);
											builder.manage_empty_grid();
										},
										connectWith: ".ur-grid-list-item"
									})
									.disableSelection();
								$(".ur-input-grids").sortable({
									containment: ".ur-builder-wrapper",
									tolerance: "pointer",
									placeholder: "ur-single-row",
									forceHelperSize: true,
									over: function () {
										$(this).addClass("ur-sortable-active");
									},
									out: function () {
										$(this).removeClass(
											"ur-sortable-active"
										);
									}
								});
								// Several lists share the ur-draggabled id, and an id selector would
								// only match the first, leaving later lists uninitialised.
								$("ul.ur-registered-list .draggable")
									.draggable({
										connectToSortable: ".ur-grid-list-item",
										containment: ".ur-registered-from",
										helper: function () {
											return $(this)
												.clone()
												.insertAfter(
													$(this)
														.closest(
															".ur-tab-contents"
														)
														.siblings(
															".ur-tab-lists"
														)
												);
										},
										// start: function (event, ui) {
										// },
										stop: function (event, ui) {
											if (
												$(ui.helper).closest(
													".ur-grid-list-item"
												).length === 0
											) {
												return;
											}
											var data_field_id = $(ui.helper)
												.attr("data-field-id")
												.replace(
													"user_registration_",
													""
												)
												.trim();
											var length_of_required = $(
												".ur-input-grids"
											).find(
												'.ur-field[data-field-key="' +
													data_field_id +
													'"]'
											).length;

											var only_one_field_index =
												$.makeArray(
													user_registration_form_builder_data.form_one_time_draggable_fields
												);
											var form_repeater_row_not_droppable_fields_lists =
												$.makeArray(
													user_registration_form_builder_data.form_repeater_row_not_droppable_fields_lists
												);
											if (
												length_of_required > 0 &&
												$.inArray(
													data_field_id,
													only_one_field_index
												) >= 0
											) {
												show_message(
													user_registration_form_builder_data
														.i18n_admin
														.i18n_user_required_field_already_there
												);
												$(ui.helper).remove();
												return;
											}

											if (
												ui.helper.closest(
													".ur-repeater-row"
												).length > 0 &&
												$.inArray(
													data_field_id,
													form_repeater_row_not_droppable_fields_lists
												) >= 0
											) {
												URFormBuilder.show_message(
													user_registration_form_builder_data.i18n_admin.i18n_repeater_fields_not_droppable.replace(
														"%field%",
														$(
															"li[data-field-id='user_registration_" +
																data_field_id +
																"']:first"
														).text()
													)
												);
												$(ui.helper).remove();
												return;
											}

											var clone = $(ui.helper);
											var form_field_id =
												$(clone).attr("data-field-id");
											if (
												typeof form_field_id !==
												"undefined"
											) {
												var this_clone = $(ui.helper)
													.closest(
														".ur-grid-list-item"
													)
													.find(
														'li[data-field-id="' +
															$(this).attr(
																"data-field-id"
															) +
															'"]'
													);
												builder.populate_dropped_node(
													this_clone,
													form_field_id
												);
											}
										}
									})
									.disableSelection();
							},
							remove_selected_item: function () {
								var $this = this;
								$("body").on(
									"click",
									".ur-selected-item .ur-action-buttons .ur-trash",
									function (e) {
										var removed_item = $(this)
												.closest(".ur-selected-item ")
												.find(
													"[data-field='field_name']"
												)
												.val(),
											ele = $this,
											$ele = $(this),
											delete_item = true;

										// Get fieldKey from data-field-key attribute.
										var fieldKey = $ele
											.closest(".ur-selected-item")
											.find(".ur-field")
											.data("field-key");

										// Get field name.
										var fieldName = $ele
											.closest(".ur-selected-item")
											.find(
												'input[name="' +
													fieldKey +
													'_advance_setting[field_name]"]'
											)
											.val();

										// Get label text from label tag, excluding any span tags
										var label = $ele
											.closest(".ur-selected-item")
											.find(".ur-label label")
											.contents()
											.filter(function () {
												return this.nodeType === 3; // Filter out non-text nodes (e.g. <span> tags)
											})
											.text()
											.trim();
										var is_auto_generate_pass_enable = $(
											"#user_registration_pro_auto_password_activate"
										).is(":checked");
										if (
											$.inArray(
												fieldKey,
												user_registration_form_builder_data.ur_form_non_deletable_fields
											) > -1
										) {
											if (
												"user_pass" === fieldKey &&
												!is_auto_generate_pass_enable
											) {
												show_feature_notice(
													fieldKey,
													label
												);
												return;
											}
											if ("user_pass" !== fieldKey) {
												show_feature_notice(
													fieldKey,
													label
												);
												return;
											}
										}

										var data = {
											delete_item: delete_item,
											removed_item: removed_item,
											label: label
										};

										$(document).trigger(
											"user_registration_before_admin_field_remove",
											[data]
										);

										if (data.delete_item) {
											ur_confirmation(
												user_registration_form_builder_data
													.i18n_admin
													.i18n_are_you_sure_want_to_delete_field,
												{
													title: user_registration_form_builder_data
														.i18n_admin
														.i18n_msg_delete,
													showCancelButton: true,
													confirmButtonText:
														user_registration_form_builder_data
															.i18n_admin
															.i18n_choice_ok,
													cancelButtonText:
														user_registration_form_builder_data
															.i18n_admin
															.i18n_choice_cancel,
													ele: ele,
													$ele: $ele,
													removed_item: removed_item,
													confirm: function () {
														$ele.closest(
															".ur-selected-item "
														).remove();
														ele.check_grid();
														builder.manage_empty_grid();
														builder.manage_draggable_users_fields();

														// Remove item from conditional logic options
														$(
															'[class*="urcl-settings-rules_field_"] option[value="' +
																removed_item +
																'"]'
														).remove();

														// Remove Field from Form Setting Conditionally Assign User Role.
														$(
															'[class*="urcl-field-conditional-field-select"] option[value="' +
																removed_item +
																'"]'
														).remove();

														$(
															'[id="user_registration_form_setting_default_phone_field"] option[value="' +
																removed_item +
																'"]'
														).remove();

														$(
															document.body
														).trigger(
															"ur_field_removed",
															[
																{
																	fieldName:
																		fieldName,
																	fieldKey:
																		fieldKey,
																	label: label
																}
															]
														);

														// To prevent click on whole item.
														return false;
													},
													reject: function () {
														return false;
													}
												}
											);
										}
									}
								);
							},

							clone_selected_item: function () {
								$("body").on(
									"click",
									".ur-selected-item .ur-action-buttons  .ur-clone",
									function () {
										var data_field_key = $(this)
											.closest(".ur-selected-item ")
											.find(".ur-field")
											.attr("data-field-key");
										var selected_node = $(
											".ur-input-grids"
										).find(
											'.ur-field[data-field-key="' +
												data_field_key +
												'"]'
										);
										var length_of_required =
											selected_node.length;
										if (
											length_of_required > 0 &&
											$.inArray(
												data_field_key,
												user_registration_form_builder_data.form_one_time_draggable_fields
											) > -1
										) {
											URFormBuilder.show_message(
												user_registration_form_builder_data
													.i18n_admin
													.i18n_user_required_field_already_there_could_not_clone
											);
											return;
										}
										var clone = $(this)
											.closest(".ur-selected-item ")
											.clone();
										var label_node = clone.find(
											'input[data-field="field_name"]'
										);
										var regex = /\d+/g;
										var matches = label_node
											.val()
											.match(regex);
										var find_string =
											matches.length > 0
												? matches[matches.length - 1]
												: "";
										var label_string = label_node
											.val()
											.replace(find_string, "");
										clone
											.find(
												'input[data-field="field_name"]'
											)
											.attr(
												"value",
												label_string +
													new Date().getTime()
											);
										$(this)
											.closest(".ur-grid-list-item")
											.append(clone);

										var populated_item = clone
											.find("[data-field='field_name']")
											.val();
										URFormBuilder.manage_conditional_field_options(
											populated_item
										);
									}
								);
							},
							check_grid: function () {
								$(".ur-tabs").tabs({ disabled: [1] });
								$(".ur-tabs")
									.find("a")
									.eq(0)
									.trigger("click", ["triggered_click"]);
							}
						};
						builder.init();
						events.register();
					});
				};

				$(".ur-input-grids").ur_form_builder();
				$(".ur-tabs .ur-tab-lists").on(
					"click",
					"a.nav-tab",
					function () {
						$(".ur-tabs .ur-tab-lists")
							.find("a.nav-tab")
							.removeClass("active");
						$(this).addClass("active");

						$(".ur-multiselect").each(function () {
							var $el = $(this);
							if ($el.hasClass("select2-hidden-accessible")) {
								try {
									$el.select2("destroy");
								} catch (e) {}
							}
							$el.select2();
							var $wrap = $el.closest(".ur-general-setting");
							if ($wrap.length) {
								var $containers =
									$wrap.find(".select2-container");
								$containers.slice(1).remove();
							}
						});
					}
				);
				$(".ur-tabs").tabs();
				$(".ur-tabs")
					.find("a")
					.eq(0)
					.trigger("click", ["triggered_click"]);
				$(".ur-tabs").tabs({ disabled: [1] });
			},
			manage_conditional_field_options: function (populated_item) {
				$(".ur-grid-lists .ur-selected-item .ur-general-setting").each(
					function () {
						var field_label = $(this)
							.closest(".ur-selected-item")
							.find(" .ur-admin-template .ur-label label")
							.text();
						var field_key = $(this)
							.closest(".ur-selected-item")
							.find(" .ur-admin-template .ur-field")
							.data("field-key");

						//strip certain fields
						if (
							"section_title" == field_key ||
							"html" == field_key ||
							"wysiwyg" == field_key ||
							"billing_address_title" == field_key ||
							"shipping_address_title" == field_key
						) {
							return;
						}

						var field_name = $(this)
							.find("[data-field='field_name']")
							.val();
						if (typeof field_name !== "undefined") {
							var select_value_for_user_role =
								$(
									".urcl-field-conditional-field-select option[value='" +
										field_name +
										"']"
								).length > 0;
							if (select_value_for_user_role === false) {
								// Append Field in Form Setting Conditionally Assign User Role.
								$(
									'[class*="urcl-field-conditional-field-select"]'
								).append(
									'<option value ="' +
										field_name +
										'" data-type="' +
										field_key +
										'">' +
										field_label +
										" </option>"
								);
							}
							//check if option exist in the given select
							var select_value =
								$(
									".urcl-rules select.ur_advance_setting.urcl-settings-rules_field_1 option[value='" +
										field_name +
										"']"
								).length > 0;
							if (select_value === false) {
								// Append Field in Field Options
								$(
									'[class*="urcl-settings-rules_field_"]'
								).append(
									'<option value ="' +
										field_name +
										'" data-type="' +
										field_key +
										'">' +
										field_label +
										" </option>"
								);

								if (field_name == populated_item) {
									$(
										'.urcl-rules select.ur_advance_setting.urcl-settings-rules_field_1.empty-fields option[value="' +
											populated_item +
											'"]'
									).remove();
								}
							} else {
								$(
									".urcl-rules select.ur_advance_setting.urcl-settings-rules_field_1.empty-fields"
								).append(
									'<option value ="' +
										field_name +
										'" data-type="' +
										field_key +
										'">' +
										field_label +
										" </option>"
								);
							}

							// Handle Drag Phone field option set for sms verification field list.
							if ("phone" === field_key) {
								var phone_format = $(this)
									.parent()
									.find(".ur-general-setting-select-format")
									.find("[data-field='phone_format']")
									.val();

								if (
									0 >=
									$(
										"#user_registration_form_setting_default_phone_field"
									).length
								) {
									var html =
										'<div class="form-row ur-enhanced-select" id="user_registration_form_setting_default_phone_field_field" data-priority="">';
									html +=
										'<label for="user_registration_form_setting_default_phone_field" class="ur-label">' +
										user_registration_form_builder_data
											.i18n_admin
											.i18n_default_phone_field +
										"</label>";
									html +=
										'<select data-rules="" data-id="user_registration_form_setting_default_phone_field" name="user_registration_form_setting_default_phone_field" id="user_registration_form_setting_default_phone_field" class="select " data-allow_clear="true" data-placeholder="">';
									html +=
										'<option value="' +
										field_name +
										'" data-phone-format="' +
										phone_format +
										'">' +
										field_label +
										"</option>";
									html += "</select></div>";
									$(
										"#user_registration_form_setting_login_options_field"
									).after(html);

									// 	Hide SMS Verification phone field mapping setting if not set to sms verification
									if (
										$(
											"#user_registration_form_setting_login_options"
										).val() === "sms_verification"
									) {
										$(
											"#user_registration_form_setting_default_phone_field"
										)
											.parent()
											.show();
										$(
											"#user_registration_form_setting_sms_verification_msg_field"
										).show();
									} else {
										$(
											"#user_registration_form_setting_default_phone_field"
										)
											.parent()
											.hide();
										$(
											"#user_registration_form_setting_sms_verification_msg_field"
										).hide();
									}
								} else {
									$(
										'#user_registration_form_setting_default_phone_field option[value="' +
											field_name +
											'"]'
									).remove();

									$(
										"#user_registration_form_setting_default_phone_field"
									).append(
										'<option value ="' +
											field_name +
											'" data-phone-format="' +
											phone_format +
											'">' +
											field_label +
											" </option>"
									);
								}
							}
						}
					}
				);
				$(
					".urcl-rules select.ur_advance_setting.urcl-settings-rules_field_1.empty-fields"
				).removeClass("empty-fields");
			},
			/**
			 * Handles all the operations performed on a selected field.
			 */
			handle_selected_item: function (selected_item) {
				$(".ur-selected-item").removeClass("ur-item-active");
				$(selected_item).addClass("ur-item-active");
				URFormBuilder.render_advance_setting($(selected_item));
				URFormBuilder.init_events();
				$(document).trigger("update_perfect_scrollbar");

				var field_key = $(selected_item)
					.find(".ur-field")
					.data("field-key");

				$(document).trigger("user_registration_handle_selected_item", [
					selected_item
				]);

				if (
					"country" === field_key ||
					"billing_country" === field_key ||
					"shipping_country" === field_key
				) {
					/**
					 * Bind UI actions for `Selective Countries` feature
					 */
					var $selected_countries_option_field = $(
						"#ur-setting-form select.ur-settings-selected-countries"
					);
					$selected_countries_option_field
						.on("change", function (e) {
							var selected_countries_iso_s = $(this).val();
							var html =
								"<option value=''>" +
								user_registration_form_settings_params.ur_default_country_value_option +
								"</option>";
							var self = this;

							// Get html of selected countries
							if (Array.isArray(selected_countries_iso_s)) {
								selected_countries_iso_s.forEach(
									function (iso) {
										var country_name = $(self)
											.find('option[value="' + iso + '"]')
											.html();
										html +=
											'<option value="' +
											iso +
											'">' +
											country_name +
											"</option>";
									}
								);
							}

							// Update default_value options in `Field Options` tab
							$(
								"#ur-setting-form select.ur-settings-default-value"
							).html(html);

							// Update default_value options (hidden)
							$(
								".ur-selected-item.ur-item-active select.ur-settings-default-value"
							).html(html);
						})
						.select2({
							placeholder: "Select countries...",
							selectionAdapter: SelectionAdapter,
							dropdownAdapter: DropdownAdapter,
							templateResult: function (data) {
								if (!data.id) {
									return data.text;
								}

								return $("<div></div>")
									.text(data.text)
									.addClass("wrap");
							},
							templateSelection: function (data) {
								if (!data.id) {
									return data.text;
								}
								var length = 0;

								if ($selected_countries_option_field.val()) {
									length =
										$selected_countries_option_field.val()
											.length;
								}

								return "Selected " + length + " country(s)";
							}
						})
						.on("change", function (e) {
							$(".urcl-rules, .urcl-conditional-group").each(
								function () {
									var $urcl_field = $(this).find(
										".urcl-field"
									).length
										? $(this).find(".urcl-field")
										: $(this).find(".urcl-form-group");
									var type = $urcl_field
										.find("select option:selected")
										.data("type");

									if (
										"country" === type ||
										"billing_country" === type ||
										"shipping_country" === type
									) {
										var field_name = $urcl_field
											.find("select option:selected")
											.val();
										var selected_value = $(this)
											.find(".urcl-value select")
											.val();
										var countries = $(
											'.ur-general-setting-field-name input[value="' +
												field_name +
												'"'
										)
											.closest(".ur-selected-item")
											.find(
												".ur-advance-selected_countries select option:selected"
											);
										var options_html = [];

										$(this)
											.find(".urcl-value select")
											.html(
												'<option value="">--select--</option>'
											);
										countries.each(function () {
											var country_iso = $(this).val();
											var country_name = $(this).text();

											options_html.push(
												'<option value="' +
													country_iso +
													'">' +
													country_name +
													"</option>"
											);
										});
										$(this)
											.find(".urcl-value select")
											.append(options_html.join(""));
										$(this)
											.find(".urcl-value select")
											.val(selected_value);
										$(this)
											.find(
												'.urcl-value select option[value="' +
													selected_value +
													'"]'
											)
											.attr("selected", "selected");
									}
								}
							);
						})
						/**
						 * The following block of code is required to fix the following issue:
						 * - When the dropdown is open, if the contents of this option's container changes, for example when a different field is
						 * activated, the behaviour of input tags changes. Specifically, when pressed space key inside ANY input tag, the dropdown
						 * APPEARS.
						 *
						 * P.S. The option we're talking about is `Selective Countries` for country field.
						 */
						.on("select2:close", function (e) {
							setTimeout(function () {
								$(":focus").trigger("blur");
							}, 1);
						});
				}

				$(document.body).trigger("ur_rendered_field_options", [
					selected_item
				]);
				$(document.body).trigger("init_tooltips");
				$(document.body).trigger("init_field_options_toggle");
			},
			/**
			 * Render the advance setting for selected field.
			 *
			 * @param Object selected_obj Selected field in the form builder area.
			 */
			render_advance_setting: function (selected_obj) {
				var advance_setting = selected_obj
					.find(".ur-advance-setting-block")
					.clone();
				var general_setting = selected_obj
					.find(".ur-general-setting-block")
					.clone();
				var form = $("<form id='ur-setting-form'/>");
				$("#ur-tab-field-options").html("");
				form.append(general_setting);
				form.append(advance_setting);
				$("#ur-tab-field-options").append(form);
				// $("#ur-tab-field-options").append(advance_setting);
				$("#ur-tab-field-options")
					.find(".ur-advance-setting-block")
					.show();
				$("#ur-tab-field-options")
					.find(".ur-general-setting-block")
					.show();
				if ($(".ur-item-active").length === 1) {
					$(".ur-tabs").tabs().tabs("enable", 1);
					$(".ur-tabs")
						.find("a")
						.eq(1)
						.trigger("click", ["triggered_click"]);
				}
				$(".ur-options-list").sortable({
					containment: ".ur-general-setting-options"
				});
			},

			/**
			 * Trigger the changes from field settings and reflect the changes in the form fields in form builder area.
			 */
			init_events: function () {
				var general_setting = $(".ur-general-setting-field");
				$.each(general_setting, function () {
					var $this_obj = $(this);
					switch ($this_obj.attr("data-field")) {
						case "label":
							$this_obj.on("keyup", function () {
								URFormBuilder.trigger_general_setting_label(
									$(this)
								);
							});
							break;
						case "field_name":
						case "max_files":
						case "input_mask":
						case "hidden_value":
						case "custom_class":
							$this_obj.on("change", function () {
								URFormBuilder.trigger_general_setting_field_name(
									$(this)
								);
							});
						case "phone_format":
							$this_obj.on("change", function () {
								var wrapper = $(
									".ur-selected-item.ur-item-active"
								);

								var old_field_name = wrapper
									.find(".ur-advance-setting-block")
									.find('input[data-field="field_name"]')
									.attr("value");

								// Change Field Name of field in Form Setting Default Phone field for SMS Verification.
								$(
									'[id="user_registration_form_setting_default_phone_field"] option[value="' +
										old_field_name +
										'"]'
								).attr("data-phone-format", $this_obj.val());
							});
						case "default_value":
							$this_obj.on("change", function () {
								if (
									"default_value" ===
									$this_obj.attr("data-field")
								) {
									if (
										$this_obj
											.closest(
												".ur-general-setting-block"
											)
											.hasClass(
												"ur-general-setting-select"
											)
									) {
										URFormBuilder.render_select_box(
											$(this)
										);
									} else if (
										$this_obj
											.closest(
												".ur-general-setting-block"
											)
											.hasClass(
												"ur-general-setting-radio"
											)
									) {
										URFormBuilder.render_radio($(this));
									} else if (
										$this_obj
											.closest(
												".ur-general-setting-block"
											)
											.hasClass(
												"ur-general-setting-checkbox"
											)
									) {
										URFormBuilder.render_check_box($(this));
									} else if (
										$this_obj
											.closest(
												".ur-general-setting-block"
											)
											.hasClass(
												"ur-general-setting-multiple_choice"
											)
									) {
										URFormBuilder.render_multiple_choice(
											$(this)
										);
									} else if (
										$this_obj
											.closest(
												".ur-general-setting-block"
											)
											.hasClass(
												"ur-general-setting-subscription_plan"
											)
									) {
										URFormBuilder.render_subscription_plan(
											$(this)
										);
									}
								}
							});
							break;
						case "options":
							$this_obj.on("keyup", function () {
								if (
									($this_obj
										.closest(".ur-general-setting-block")
										.hasClass(
											"ur-general-setting-select"
										) ||
										$this_obj
											.closest(
												".ur-general-setting-block"
											)
											.hasClass(
												"ur-general-setting-select2"
											)) &&
									$this_obj.siblings(
										'input[data-field="default_value"]'
									).length > 0
								) {
									URFormBuilder.render_select_box($(this));
								} else if (
									$this_obj
										.closest(".ur-general-setting-block")
										.hasClass(
											"ur-general-setting-multi_select2"
										) &&
									$this_obj.siblings(
										'input[data-field="default_value"]'
									).length > 0
								) {
									URFormBuilder.render_multi_select_box(
										$(this)
									);
								} else if (
									$this_obj
										.closest(".ur-general-setting-block")
										.hasClass("ur-general-setting-radio")
								) {
									URFormBuilder.render_radio($(this));
								} else if (
									$this_obj
										.closest(".ur-general-setting-block")
										.hasClass("ur-general-setting-checkbox")
								) {
									URFormBuilder.render_check_box($(this));
								} else if (
									$this_obj
										.closest(".ur-general-setting-block")
										.hasClass(
											"ur-general-setting-multiple_choice"
										)
								) {
									URFormBuilder.render_multiple_choice(
										$(this)
									);
								} else if (
									$this_obj
										.closest(".ur-general-setting-block")
										.hasClass(
											"ur-general-setting-subscription_plan"
										)
								) {
									URFormBuilder.render_subscription_plan(
										$(this)
									);
								}

								URFormBuilder.trigger_general_setting_options(
									$(this)
								);
							});

							$this_obj.on("change", function () {
								if (
									$this_obj
										.closest(".ur-general-setting-block")
										.hasClass(
											"ur-general-setting-subscription_plan"
										)
								) {
									URFormBuilder.render_subscription_plan(
										$(this)
									);
								}
							});

							$(".ur-radio-enable-trail-period").each(
								function () {
									if ($(this).is(":checked")) {
										$(this)
											.closest(".ur-subscription-plan")
											.find(
												".ur-subscription-trail-period-option"
											)
											.show();
									} else {
										$(this)
											.closest(".ur-subscription-plan")
											.find(
												".ur-subscription-trail-period-option"
											)
											.hide();
									}
									$(this).on("change", function () {
										if ($(this).is(":checked")) {
											$(this)
												.closest(
													".ur-subscription-plan"
												)
												.find(
													".ur-subscription-trail-period-option"
												)
												.show();
										} else {
											$(this)
												.closest(
													".ur-subscription-plan"
												)
												.find(
													".ur-subscription-trail-period-option"
												)
												.hide();
										}
									});
								}
							);
							$(".ur-radio-enable-expiry-date").each(function () {
								if ($(this).is(":checked")) {
									$(this)
										.closest(".ur-subscription-plan")
										.find(".ur-subscription-expiry-option")
										.show();
								} else {
									$(this)
										.closest(".ur-subscription-plan")
										.find(".ur-subscription-expiry-option")
										.hide();
									$(this)
										.closest(".ur-subscription-plan")
										.find(".ur-subscription-expiry-option")
										.find(".ur-subscription-expiry-date")
										.val("");
								}
								$(this).on("change", function () {
									if ($(this).is(":checked")) {
										$(this)
											.closest(".ur-subscription-plan")
											.find(
												".ur-subscription-expiry-option"
											)
											.show();
										$(this)
											.closest(".ur-subscription-plan")
											.find(
												".ur-subscription-expiry-option"
											)
											.find(
												".ur-subscription-expiry-date"
											)
											.val("");
									} else {
										$(this)
											.closest(".ur-subscription-plan")
											.find(
												".ur-subscription-expiry-option"
											)
											.hide();
										$(this)
											.closest(".ur-subscription-plan")
											.find(
												".ur-subscription-expiry-option"
											)
											.find(
												".ur-subscription-expiry-date"
											)
											.val("");
									}
								});
							});

							break;
						case "selling_price":
							if (!$this_obj.is(":checked")) {
								$(this)
									.closest(".ur-general-setting-block")
									.find(".ur-selling-price")
									.hide();
							}

							$this_obj.on("change", function () {
								$(this)
									.closest(".ur-general-setting-block")
									.find(".ur-selling-price")
									.toggle();

								$(".ur-selected-item.ur-item-active")
									.find(".ur-general-setting-block")
									.find(".ur-selling-price")
									.toggle();
							});
							$this_obj.on("change", function () {
								URFormBuilder.trigger_general_setting_selling_price(
									$(this)
								);
							});
							break;
						case "trail_period":
							if (!$this_obj.is(":checked")) {
								$(this)
									.closest(".ur-general-setting-block")
									.find(
										".ur-subscription-trail-period-option"
									)
									.hide();
							}

							$this_obj.on("change", function () {
								$(this)
									.closest(".ur-general-setting-block")
									.find(
										".ur-subscription-trail-period-option"
									)
									.toggle();

								$(".ur-selected-item.ur-item-active")
									.find(".ur-general-setting-block")
									.find(
										".ur-subscription-trail-period-option"
									)
									.toggle();
							});
							$this_obj.on("change", function () {
								URFormBuilder.trigger_general_setting_trail_period(
									$(this)
								);
							});
							break;
						case "placeholder":
							$this_obj.on("keyup", function () {
								URFormBuilder.trigger_general_setting_placeholder(
									$(this)
								);
							});
							break;
						case "required":
							$this_obj.on("change", function () {
								URFormBuilder.trigger_general_setting_required(
									$(this)
								);
							});
							break;
						case "hide_label":
							$this_obj.on("change", function () {
								URFormBuilder.trigger_general_setting_hide_label(
									$(this)
								);
							});
							break;
						case "description":
						case "html":
							$this_obj.on("keyup", function () {
								URFormBuilder.trigger_general_setting_description(
									$(this)
								);
							});
							break;
					}
					$(document.body).trigger(
						"ur_general_field_settings_to_update_form_fields_in_builder",
						[$this_obj]
					);
				});
				var advance_settings = $(
					"#ur-setting-form .ur_advance_setting"
				);

				$(".ur-settings-enable-min-max").on("change", function () {
					if ($(this).is(":checked")) {
						$(
							".ur-item-active .ur-advance-min_date, #ur-setting-form .ur-advance-min_date"
						).show();
						$(
							".ur-item-active .ur-advance-max_date, #ur-setting-form .ur-advance-max_date"
						).show();

						$("#ur-setting-form .ur-settings-min-date")
							.addClass("flatpickr-field")
							.flatpickr({
								disableMobile: true,
								onChange: function (
									selectedDates,
									dateStr,
									instance
								) {
									$(
										".ur-item-active .ur-settings-min-date"
									).val(dateStr);
								},
								onOpen: function (
									selectedDates,
									dateStr,
									instance
								) {
									instance.set(
										"maxDate",
										// new Date(
										$(
											".ur-item-active .ur-settings-max-date"
										).val()
										// )
									);
								}
							});

						$("#ur-setting-form .ur-settings-max-date")
							.addClass("flatpickr-field")
							.flatpickr({
								disableMobile: true,
								onChange: function (
									selectedDates,
									dateStr,
									instance
								) {
									$(
										".ur-item-active .ur-settings-max-date"
									).val(dateStr);
								},
								onOpen: function (
									selectedDates,
									dateStr,
									instance
								) {
									instance.set(
										"minDate",
										// new Date(
										$(
											".ur-item-active .ur-settings-min-date"
										).val()
										// )
									);
								}
							});
					} else {
						$(
							".ur-item-active .ur-advance-min_date, #ur-setting-form .ur-advance-min_date"
						).hide();
						$(
							".ur-item-active .ur-advance-max_date, #ur-setting-form .ur-advance-max_date"
						).hide();
					}
				});

				$.each(advance_settings, function () {
					var $this_node = $(this);

					switch ($this_node.attr("data-advance-field")) {
						case "step":
							$this_node.on("keyup keydown", function () {
								$this_node.attr("step", $this_node.val());
							});
							break;
						case "limit_length":
						case "minimum_length":
							$this_node.on("change", function () {
								URFormBuilder.handle_min_max_length($this_node);
							});
							URFormBuilder.handle_min_max_length($this_node);
							break;
						case "date_format":
							$this_node.on("change", function () {
								URFormBuilder.trigger_general_setting_date_format(
									$(this)
								);
							});
							break;
						case "min_date":
							if (
								$(".ur-item-active")
									.find(".ur-settings-enable-min-max")
									.is(":checked")
							) {
								$(this)
									.addClass("flatpickr-field")
									.flatpickr({
										disableMobile: true,
										defaultDate: new Date(
											$(".ur-item-active")
												.find(".ur-settings-min-date")
												.val()
										),
										onChange: function (
											selectedDates,
											dateStr,
											instance
										) {
											$(".ur-item-active")
												.find(".ur-settings-min-date")
												.val(dateStr);
										},
										onOpen: function (
											selectedDates,
											dateStr,
											instance
										) {
											instance.set(
												"maxDate",
												new Date(
													$(".ur-item-active")
														.find(
															".ur-settings-max-date"
														)
														.val()
												)
											);
										}
									});
							} else {
								$(
									".ur-item-active .ur-advance-min_date, #ur-setting-form .ur-advance-min_date"
								).hide();
							}
							break;
						case "max_date":
							if (
								$(".ur-item-active")
									.find(".ur-settings-enable-min-max")
									.is(":checked")
							) {
								$(this)
									.addClass("flatpickr-field")
									.flatpickr({
										disableMobile: true,
										defaultDate: new Date(
											$(".ur-item-active")
												.find(".ur-settings-max-date")
												.val()
										),
										onChange: function (
											selectedDates,
											dateStr,
											instance
										) {
											$(".ur-item-active")
												.find(".ur-settings-max-date")
												.val(dateStr);
										},
										onOpen: function (
											selectedDates,
											dateStr,
											instance
										) {
											instance.set(
												"minDate",
												new Date(
													$(
														".ur-item-active .ur-settings-min-date"
													).val()
												)
											);
										}
									});
							} else {
								$(
									".ur-item-active .ur-advance-max_date, #ur-setting-form .ur-advance-max_date"
								).hide();
							}
							break;

						case "enable_prepopulate":
							if ($this_node.is(":checked")) {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-parameter_name")
									.show();
							} else {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-parameter_name")
									.hide();
							}

							$this_node.on("click", function () {
								var wrapper = $(
										".ur-selected-item.ur-item-active"
									),
									selector_field_name = $(this)
										.closest("#ur-setting-form")
										.find("[data-field='field_name']")
										.val(),
									active_field_name = wrapper
										.find("[data-field='field_name']")
										.val();
								wrapper
									.find(".ur-advance-setting-block")
									.find(
										'input[data-field="' +
											$(this).attr("data-field") +
											'"]'
									)
									.prop("checked", $(this).is(":checked"));

								if (selector_field_name === active_field_name) {
									if ($(this).is(":checked")) {
										$(this)
											.closest(
												".ur-advance-setting-block"
											)
											.find(".ur-advance-parameter_name")
											.show();
										$(".ur-selected-item.ur-item-active")
											.find(".ur-advance-parameter_name")
											.show();
									} else {
										$(this)
											.closest(
												".ur-advance-setting-block"
											)
											.find(".ur-advance-parameter_name")
											.hide();
										$(".ur-selected-item.ur-item-active")
											.find(".ur-advance-parameter_name")
											.hide();
									}
								}
							});
							break;
						case "autocomplete_address":
							if ($this_node.is(":checked")) {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-address_style")
									.show();
							} else {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-address_style")
									.hide();
							}

							$this_node.on("click", function () {
								var wrapper = $(
										".ur-selected-item.ur-item-active"
									),
									selector_field_name = $(this)
										.closest("#ur-setting-form")
										.find("[data-field='field_name']")
										.val(),
									active_field_name = wrapper
										.find("[data-field='field_name']")
										.val();
								wrapper
									.find(".ur-advance-setting-block")
									.find(
										'input[data-field="' +
											$(this).attr("data-field") +
											'"]'
									)
									.prop("checked", $(this).is(":checked"));

								if (selector_field_name === active_field_name) {
									if ($(this).is(":checked")) {
										$(this)
											.closest(
												".ur-advance-setting-block"
											)
											.find(".ur-advance-address_style")
											.show();
										$(".ur-selected-item.ur-item-active")
											.find(".ur-advance-address_style")
											.show();
									} else {
										$(this)
											.closest(
												".ur-advance-setting-block"
											)
											.find(".ur-advance-address_style")
											.hide();
										$(".ur-selected-item.ur-item-active")
											.find(".ur-advance-address_style")
											.hide();
									}
								}
							});
							break;
						case "validate_unique":
							if ($this_node.is(":checked")) {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-validation_message")
									.show();
							} else {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-validation_message")
									.hide();
							}

							$this_node.on("click", function () {
								var wrapper = $(
										".ur-selected-item.ur-item-active"
									),
									selector_field_name = $(this)
										.closest("#ur-setting-form")
										.find("[data-field='field_name']")
										.val(),
									active_field_name = wrapper
										.find("[data-field='field_name']")
										.val();
								wrapper
									.find(".ur-advance-setting-block")
									.find(
										'input[data-field="' +
											$(this).attr("data-field") +
											'"]'
									)
									.prop("checked", $(this).is(":checked"));

								if (selector_field_name === active_field_name) {
									if ($(this).is(":checked")) {
										$(this)
											.closest(
												".ur-advance-setting-block"
											)
											.find(
												".ur-advance-validation_message"
											)
											.show();
										$(".ur-selected-item.ur-item-active")
											.find(
												".ur-advance-validation_message"
											)
											.show();
									} else {
										$(this)
											.closest(
												".ur-advance-setting-block"
											)
											.find(
												".ur-advance-validation_message"
											)
											.hide();
										$(".ur-selected-item.ur-item-active")
											.find(
												".ur-advance-validation_message"
											)
											.hide();
									}
								}
							});
							break;
						case "enable_selling_price_single_item":
							if (!$this_node.is(":checked")) {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-selling_price")
									.hide();
							}

							$this_node.on("change", function () {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-selling_price")
									.toggle();

								$(".ur-selected-item.ur-item-active")
									.find(".ur-advance-selling_price")
									.toggle();
							});
							break;
						case "enable_pattern":
							if (!$this_node.is(":checked")) {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-pattern_value")
									.hide();
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-pattern_message")
									.hide();
							}

							$this_node.on("change", function () {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-pattern_value")
									.toggle();

								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-pattern_message")
									.toggle();
							});
							break;
						case "enable_time_slot_booking":
							var form = $this_node.closest("form"),
								general_settings = form.find(
									".ur-general-setting-timepicker"
								),
								requiredWrapper = general_settings.find(
									".ur-general-setting-required"
								),
								requiredField = requiredWrapper.find("input");
							if (!$this_node.is(":checked")) {
								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-target_date_field")
									.hide();
							}
							if ($this_node.is(":checked")) {
								//Required true if the slot booking is enable.
								if (!requiredField.is(":checked")) {
									requiredField.trigger("click");
									requiredField.attr("checked", true);
								}

								if (
									!$(this)
										.closest(".ur-advance-setting-block")
										.find(".ur-settings-time_range")
										.is(":checked")
								) {
									$(this)
										.closest(".ur-advance-setting-block")
										.find(".ur-settings-time_range")
										.trigger("click");
									$(this)
										.closest(".ur-advance-setting-block")
										.find(".ur-settings-time_range")
										.attr("checked", true);
								}

								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-time_range")
									.hide();
							}

							$this_node.on("change", function () {
								if ($this_node.is(":checked")) {
									//Required true if the slot booking is enable.
									if (!requiredField.is(":checked")) {
										requiredField.trigger("click");
										requiredField.attr("checked", true);
									}
								}

								$(this)
									.closest(".ur-advance-setting-block")
									.find(".ur-advance-target_date_field")
									.toggle();

								if ($(this).is(":checked")) {
									if (
										!$(this)
											.closest(
												".ur-advance-setting-block"
											)
											.find(".ur-settings-time_range")
											.is(":checked")
									) {
										$(this)
											.closest(
												".ur-advance-setting-block"
											)
											.find(".ur-settings-time_range")
											.trigger("click");
										$(this)
											.closest(
												".ur-advance-setting-block"
											)
											.find(".ur-settings-time_range")
											.attr("checked", true);
									}

									$(this)
										.closest(".ur-advance-setting-block")
										.find(".ur-advance-time_range")
										.hide();
								} else {
									$(this)
										.closest(".ur-advance-setting-block")
										.find(".ur-advance-time_range")
										.show();
								}
							});
							break;
						case "enable_date_slot_booking":
							var form = $this_node.closest("form"),
								general_settings = form.find(
									".ur-general-setting-date"
								),
								requiredWrapper = general_settings.find(
									".ur-general-setting-required"
								),
								requiredField = requiredWrapper.find("input");

							if ($this_node.is(":checked")) {
								//Required true if the slot booking is enable.
								if (!requiredField.is(":checked")) {
									requiredField.trigger("click");
									requiredField.attr("checked", true);
								}
							}

							$this_node.on("change", function () {
								if ($this_node.is(":checked")) {
									//Required true if the slot booking is enable.
									if (!requiredField.is(":checked")) {
										requiredField.trigger("click");
										requiredField.attr("checked", true);
									}
								}
							});
							break;
					}
					var node_type = $this_node.get(0).tagName.toLowerCase();

					if (
						"country_advance_setting_default_value" ===
						$this_node.attr("data-id")
					) {
						$(".ur-builder-wrapper #ur-input-type-country")
							.find('option[value="' + $this_node.val() + '"]')
							.attr("selected", "selected");
					}
					var event = "change";
					switch (node_type) {
						case "input":
							event = "keyup click";
							break;
						case "select":
							event = "change";
							break;
						case "textarea":
							event = "keyup";
							break;
						default:
							event = "change";
					}

					if (
						"valid_file_type" ===
							$this_node.attr("data-advance-field") ||
						"payment_methods" ===
							$this_node.attr("data-advance-field")
					) {
						$this_node.select2();
					}

					$(this).on(event, function () {
						URFormBuilder.trigger_advance_setting(
							$this_node,
							node_type
						);
					});
					$(this).on("paste", function () {
						URFormBuilder.trigger_advance_setting(
							$this_node,
							node_type
						);
					});
				});
			},
			/**
			 * Reflects changes in Minimum Length and Limit Length Field of field settings.
			 *
			 * @param object $this_node field from field settings.
			 */
			handle_min_max_length: function ($this_node) {
				var parentDiv = $this_node.closest(".ur-advance-setting");
				var parentNextDiv = parentDiv.next(".ur-advance-setting");
				if ($this_node.is(":checked")) {
					parentNextDiv.show();
					parentNextDiv.next(".ur-advance-setting").show();
				} else {
					parentNextDiv.hide();
					parentNextDiv.next(".ur-advance-setting").hide();
				}
			},
			/**
			 * Reflects changes in label field of field settings into selected field in form builder area.
			 *
			 * @param object $label Label field from field settings.
			 */
			trigger_general_setting_label: function ($label) {
				var wrapper = $(".ur-selected-item.ur-item-active");
				wrapper.find(".ur-label").find("label").text($label.val());

				if (
					$(".ur-selected-item.ur-item-active .ur-general-setting")
						.find("[name='ur_general_setting[required]']")
						.filter(function () {
							return (
								$(this).is(":checked") || $(this).val() === "1"
							);
						}).length
				) {
					wrapper
						.find(".ur-label")
						.find("label")
						.append('<span style="color:red">*</span>');
				}

				wrapper
					.find(".ur-general-setting-block")
					.find(
						'input[data-field="' + $label.attr("data-field") + '"]'
					)
					.attr("value", $label.val());

				var field_name = $(
					".ur-selected-item.ur-item-active .ur-general-setting"
				)
					.find("[data-field='field_name']")
					.val();
				// Change label of field in conditional logic options
				$(
					'[class*="urcl-settings-rules_field_"] option[value="' +
						field_name +
						'"]'
				).text($label.val());

				// Change label of field in Form Setting Conditionally Assign User Role.
				$(
					'[class*="urcl-field-conditional-field-select"] option[value="' +
						field_name +
						'"]'
				).text($label.val());

				// Change label of field in Form Setting Default Phone field for SMS Verification.
				$(
					'[id="user_registration_form_setting_default_phone_field"] option[value="' +
						field_name +
						'"]'
				).text($label.val());
			},
			/**
			 * Reflects changes in field name field of field settings into selected field in form builder area.
			 *
			 * @param object $label Label for field name from field settings.
			 */
			trigger_general_setting_field_name: function ($label) {
				var wrapper = $(".ur-selected-item.ur-item-active");
				var old_field_name = wrapper
					.find(".ur-general-setting-block")
					.find(
						'input[data-field="' + $label.attr("data-field") + '"]'
					)
					.attr("value");
				wrapper
					.find(".ur-general-setting-block")
					.find(
						'input[data-field="' + $label.attr("data-field") + '"]'
					)
					.attr("value", $label.val());

				// Change Field Name of field in conditional logic options
				$(
					'[class*="urcl-settings-rules_field_"] option[value="' +
						old_field_name +
						'"]'
				).attr("value", $label.val());

				// Change Field Name of field in Form Setting Conditionally Assign User Role.
				$(
					'[class*="urcl-field-conditional-field-select"] option[value="' +
						old_field_name +
						'"]'
				).attr("value", $label.val());

				// Change Field Name of field in Form Setting Default Phone field for SMS Verification.
				$(
					'[id="user_registration_form_setting_default_phone_field"] option[value="' +
						old_field_name +
						'"]'
				).attr("value", $label.val());
			},
			/**
			 * Reflects changes in textarea field of field settings into selected field in form builder area.
			 *
			 * @param object this_node Textarea field from field settings.
			 */
			render_text_area: function (value) {
				var wrapper = $(".ur-selected-item.ur-item-active");
				var field_type = wrapper.find(".ur-field");
				switch (field_type.attr("data-field-key")) {
					case "select":
						URFormBuilder.render_select_box(value);
						break;
					case "checkbox":
						URFormBuilder.render_check_box(value);
						break;
					case "radio":
						URFormBuilder.render_radio(value);
						break;
					case "multiple_choice":
						URFormBuilder.render_multiple_choice(value);
						break;
					case "subscription_plan":
						URFormBuilder.render_subscription_plan(value);
						break;
				}
				$(document.body).trigger(
					"ur_sync_textarea_field_settings_in_selected_field_of_form_builder",
					[field_type, value]
				);
			},
			/**
			 * Reflects changes in select field of field settings into selected field in form builder area.
			 *
			 * @param object this_node Select field from field settings.
			 */
			render_select_box: function (this_node) {
				var value = "";
				if (this_node.is(":checked")) {
					var value = this_node.val().trim();
				}
				var wrapper = $(".ur-selected-item.ur-item-active");
				var checked_index = this_node.closest("li").index();
				var select = wrapper.find(".ur-field").find("select");

				if (this_node.hasClass("ur-type-radio-label")) {
					value = select.val();
				}

				var options = this_node
					.closest(".ur-general-setting-options")
					.find("input.ur-general-setting-field.ur-type-radio-label")
					.map(function () {
						return $(this).val();
					});

				select.html("");
				$.each(options, function (key, option) {
					select.append(
						"<option value='" +
							option +
							"' " +
							(value === option ? "selected" : "") +
							">" +
							option +
							"</option>"
					);
				});

				// Loop through options in active fields general setting hidden div.
				wrapper
					.find(
						".ur-general-setting-options > ul.ur-options-list > li"
					)
					.each(function (index, element) {
						var radio_input = $(element).find(
							'[data-field="default_value"]'
						);
						if (index === checked_index) {
							radio_input.prop("checked", true);
						} else {
							radio_input.prop("checked", false);
						}
					});
			},
			/**
			 * Reflects changes in multi select field of field settings into selected field in form builder area.
			 *
			 * @param object this_node Multi Select field from field settings.
			 */
			render_multi_select_box: function (this_node) {
				var value = "";
				if (this_node.is(":checked")) {
					var value = this_node.val().trim();
				}
				var wrapper = $(".ur-selected-item.ur-item-active");
				var checked_index = this_node.closest("li").index();
				var select = wrapper.find(".ur-field").find("select");

				if (this_node.hasClass("ur-type-checkbox-label")) {
					value = select.val();
				}

				var options = this_node
					.closest(".ur-general-setting-options")
					.find(
						"input.ur-general-setting-field.ur-type-checkbox-label"
					)
					.map(function () {
						return $(this).val();
					});

				select.html("");
				$.each(options, function (key, option) {
					select.append(
						"<option value='" +
							option +
							"' " +
							(value === option ? "selected" : "") +
							">" +
							option +
							"</option>"
					);
				});

				// Loop through options in active fields general setting hidden div.
				wrapper
					.find(
						".ur-general-setting-options > ul.ur-options-list > li"
					)
					.each(function (index, element) {
						var radio_input = $(element).find(
							'[data-field="default_value"]'
						);
						if (index === checked_index) {
							radio_input.prop("checked", true);
						} else {
							radio_input.prop("checked", false);
						}
					});
			},
			/**
			 * Reflects changes in radio field of field settings into selected field in form builder area.
			 *
			 * @param object this_node Radio field from field settings.
			 */
			render_radio: function (this_node) {
				var li_elements = this_node.closest("ul").find("li");
				var checked_index = undefined;
				var image_image = this_node
					.closest(".ur-general-setting-options")
					.siblings(".ur-general-setting-image_choice")
					.find("input");
				var array_value = [];

				li_elements.each(function (index, element) {
					var value = $(element)
						.find("input.ur-type-radio-label")
						.val();
					value = value.trim();

					// To remove all HTML tags from a value.
					value = value.replace(/<\/?[^>]+(>|$)/g, "");

					radio = $(element)
						.find("input.ur-type-radio-value")
						.is(":checked");

					var image = $(element)
						.find("input.ur-type-image-choice")
						.val();

					// Set checked elements index value
					if (radio === true) {
						checked_index = index;
					}

					if (
						array_value.every(function (each_value) {
							return each_value.value !== value;
						})
					) {
						array_value.push({
							value: value,
							radio: radio,
							image: image
						});
					}
				});

				var wrapper = $(".ur-selected-item.ur-item-active");
				var radio = wrapper.find(".ur-field");
				radio.html("");

				for (var i = 0; i < array_value.length; i++) {
					var imageHTML = "";
					if (image_image.is(":checked")) {
						if (
							array_value[i].image &&
							array_value[i].image.trim() !== ""
						) {
							imageHTML =
								'<img src="' +
								array_value[i].image +
								'" width="200px">';
						} else {
							imageHTML =
								'<img src="' +
								user_registration_form_builder_data.ur_placeholder +
								'" width="200px">';
						}
					}
					if (array_value[i] !== "") {
						$checked_class = "";
						if (image_image.is(":checked")) {
							$checked_class = array_value[i].radio
								? "ur-image-choice-checked"
								: "";
						}
						radio.append(
							'<label class="' +
								$checked_class +
								'"><span class="user-registration-image-choice">' +
								imageHTML +
								'</span><input value="' +
								array_value[i].value.trim() +
								'" type="radio" ' +
								(array_value[i].radio ? "checked" : "") +
								" disabled>" +
								array_value[i].value.trim() +
								"</label>"
						);
					}
				}

				// Loop through options in active fields general setting hidden div.
				wrapper
					.find(
						".ur-general-setting-options > ul.ur-options-list > li"
					)
					.each(function (index, element) {
						var radio_input = $(element).find(
							'[data-field="default_value"]'
						);
						if (index === checked_index) {
							radio_input.prop("checked", true);
						} else {
							radio_input.prop("checked", false);
						}
					});
			},
			/**
			 * Reflects changes in checkbox field of field settings into selected field in form builder area.
			 *
			 * @param object this_node Checkbox field from field settings.
			 */
			render_check_box: function (this_node) {
				var array_value = [];
				var li_elements = this_node.closest("ul").find("li");
				var checked_index = this_node.closest("li").index();
				var image_image = this_node
					.closest(".ur-general-setting-options")
					.siblings(".ur-general-setting-image_choice")
					.find("input");
				li_elements.each(function (index, element) {
					var value = $(element)
						.find("input.ur-type-checkbox-label")
						.val();
					value = value.trim();

					// To remove all HTML tags (opening or closing or self closing)from a string except for anchor tags.
					value = value.replace(/<(?!\/?a\b)[^>]+>/gi, "");

					// To remove attributes except "href, target, download, rel, hreflang, type, name, accesskey, tabindex, title" from anchor tag.
					value = value.replace(
						/(?!href|target|download|rel|hreflang|type|name|accesskey|tabindex|title)\b\w+=['"][^'"]*['"]/g,
						""
					);

					// To add a closing </a> tag to a string if an open <a> tag is present but not closed.
					if (/<a(?:(?!<\/a>).)*$/.test(value)) {
						value += "</a>";
					}

					checkbox = $(element)
						.find("input.ur-type-checkbox-value")
						.is(":checked");
					var image = $(element)
						.find("input.ur-type-image-choice")
						.val();

					if (
						array_value.every(function (each_value) {
							return each_value.value !== value;
						})
					) {
						array_value.push({
							value: value,
							checkbox: checkbox,
							image: image
						});
					}
				});

				var wrapper = $(".ur-selected-item.ur-item-active");
				var checkbox = wrapper.find(".ur-field");
				checkbox.html("");

				for (var i = 0; i < array_value.length; i++) {
					var imageHTML = "";
					if (image_image.is(":checked")) {
						if (
							array_value[i].image &&
							array_value[i].image.trim() !== ""
						) {
							imageHTML =
								'<img src="' +
								array_value[i].image +
								'" width="200px">';
						} else {
							imageHTML =
								'<img src="' +
								user_registration_form_builder_data.ur_placeholder +
								'" width="200px">';
						}
					}
					if (array_value[i] !== "") {
						array_value[i].value = array_value[i].value.replaceAll(
							'"',
							"'"
						);
						$checked_class = "";
						if (image_image.is(":checked")) {
							$checked_class = array_value[i].checkbox
								? "ur-image-choice-checked"
								: "";
						}
						checkbox.append(
							'<label class="' +
								$checked_class +
								'"><span class="user-registration-image-choice">' +
								imageHTML +
								'</span><input value="' +
								array_value[i].value.trim() +
								'" type="checkbox" ' +
								(array_value[i].checkbox ? "checked" : "") +
								" disabled>" +
								array_value[i].value.trim() +
								"</label>"
						);
					}
				}

				if ("checkbox" === this_node.attr("type")) {
					if (this_node.is(":checked")) {
						wrapper
							.find(
								".ur-general-setting-options li:nth(" +
									checked_index +
									') input[data-field="default_value"]'
							)
							.prop("checked", true);
					} else {
						wrapper
							.find(
								".ur-general-setting-options li:nth(" +
									checked_index +
									') input[data-field="default_value"]'
							)
							.prop("checked", false);
					}
				}
			},
			/**
			 * Reflects changes in multiple choice field of field settings into selected field in form builder area.
			 *
			 * @param object this_node  multiple choice  field from field settings.
			 *
			 * @since 2.0.3
			 */
			render_multiple_choice: function (this_node) {
				var array_value = [];
				var li_elements = this_node.closest("ul").find("li");
				var checked_index = this_node.closest("li").index();
				var image_image = this_node
					.closest(".ur-general-setting-options")
					.siblings(".ur-general-setting-image_choice")
					.find("input");

				li_elements.each(function (index, element) {
					var label = $(element)
						.find("input.ur-type-checkbox-label")
						.val();
					var value = $(element)
						.find("input.ur-type-checkbox-money-input")
						.val();
					var sell_value = $(element)
						.find("input.ur-checkbox-selling-price-input")
						.val();
					var image = $(element)
						.find("input.ur-type-image-choice")
						.val();
					var currency = $(element)
						.find("input.ur-type-checkbox-money-input")
						.attr("data-currency");

					label = label.trim();
					value = value.trim();
					sell_value = sell_value.trim();
					image = image.trim();
					currency = currency.trim();
					checkbox = $(element)
						.find("input.ur-type-checkbox-value")
						.is(":checked");

					if (
						array_value.every(function (each_value) {
							return each_value.label !== label;
						})
					) {
						array_value.push({
							label: label,
							value: value,
							sell_value: sell_value,
							image: image,
							currency: currency,
							checkbox: checkbox
						});
					}
				});

				var wrapper = $(".ur-selected-item.ur-item-active");
				var checkbox = wrapper.find(".ur-field");
				checkbox.html("");

				for (var i = 0; i < array_value.length; i++) {
					var imageHTML = "";
					if (image_image.is(":checked")) {
						if (
							array_value[i].image &&
							array_value[i].image.trim() !== ""
						) {
							imageHTML =
								'<img src="' +
								array_value[i].image +
								'" width="200px">';
						} else {
							imageHTML =
								'<img src="' +
								user_registration_form_builder_data.ur_placeholder +
								'" width="200px">';
						}
					}
					if (array_value[i] !== "") {
						$checked_class = "";
						if (image_image.is(":checked")) {
							$checked_class = array_value[i].checkbox
								? "ur-image-choice-checked"
								: "";
						}
						checkbox.append(
							'<label class="' +
								$checked_class +
								'"><span class="user-registration-image-choice">' +
								imageHTML +
								'</span><input value="' +
								array_value[i].label.trim() +
								'" type="checkbox" ' +
								(array_value[i].checkbox ? "checked" : "") +
								" disabled>" +
								array_value[i].label.trim() +
								" - " +
								array_value[i].currency.trim() +
								" " +
								array_value[i].value.trim() +
								"</label>"
						);
					}
				}

				if ("checkbox" === this_node.attr("type")) {
					if (this_node.is(":checked")) {
						wrapper
							.find(
								".ur-general-setting-options li:nth(" +
									checked_index +
									') input[data-field="default_value"]'
							)
							.prop("checked", true);
					} else {
						wrapper
							.find(
								".ur-general-setting-options li:nth(" +
									checked_index +
									') input[data-field="default_value"]'
							)
							.prop("checked", false);
					}
				}
			},
			/**
			 * Reflects changes in multiple choice field of field settings into selected field in form builder area.
			 *
			 * @param object this_node  multiple choice  field from field settings.
			 *
			 * @since 2.0.3
			 */
			render_subscription_plan: function (this_node) {
				var array_value = [];
				var wrapper = $(".ur-selected-item.ur-item-active");
				var li_elements = this_node.closest("ul").find("li");
				var checked_index = this_node.closest("li").index();

				li_elements.each(function (index, element) {
					var label = $(element)
						.find("input.ur-type-radio-label")
						.val();
					var value = $(element)
						.find("input.ur-type-radio-money-input")
						.val();
					var sell_value = $(element)
						.find("input.ur-radio-selling-price-input")
						.val();
					var interval_count = $(element)
						.find("input.ur-radio-interval-count-input")
						.val();
					var recurring_period = $(element)
						.find(".ur-radio-recurring-period")
						.val();

					var trail_interval_count = $(element)
						.find("input.ur-radio-trail-interval-count-input")
						.val();
					var subscription_expiry_date = $(element)
						.find("input.ur-subscription-expiry-date")
						.val();
					var trail_recurring_period = $(element)
						.find(".ur-radio-trail-recurring-period")
						.val();

					var trail_period_enable_val = $(element)
						.find(".ur-radio-enable-trail-period")
						.prop("checked")
						? "on"
						: "false";

					wrapper
						.find(
							".ur-general-setting-options li:nth(" +
								index +
								") .ur-radio-enable-trail-period"
						)
						.val(trail_period_enable_val);
					var subscription_enable_val = $(element)
						.find(".ur-radio-enable-expiry-date")
						.prop("checked")
						? "on"
						: "false";

					wrapper
						.find(
							".ur-general-setting-options li:nth(" +
								index +
								") .ur-radio-enable-expiry-date"
						)
						.val(subscription_enable_val);

					var inner_toggle_wrapper = wrapper.find(
						".ur-general-setting-options li:nth(" +
							index +
							") .ur-radio-enable-expiry-date"
					);
					if (inner_toggle_wrapper.val() === "on") {
						inner_toggle_wrapper.prop("checked", true);
					} else {
						inner_toggle_wrapper.prop("checked", false);
					}

					wrapper
						.find(
							".ur-general-setting-options li:nth(" +
								index +
								") .ur-radio-recurring-period"
						)
						.val(recurring_period);
					wrapper
						.find(
							".ur-general-setting-options li:nth(" +
								index +
								") .ur-radio-trail-recurring-period"
						)
						.val(trail_recurring_period);
					wrapper
						.find(
							".ur-general-setting-options li:nth(" +
								index +
								") .ur-subscription-expiry-date"
						)
						.val(subscription_expiry_date);

					var currency = $(element)
						.find("input.ur-type-radio-money-input")
						.attr("data-currency");

					label = label.trim();
					value = value.trim();
					sell_value = sell_value.trim();
					currency = currency.trim();
					checkbox = $(element)
						.find("input.ur-type-radio-value")
						.is(":checked");

					if (
						array_value.every(function (each_value) {
							return each_value.label !== label;
						})
					) {
						array_value.push({
							label: label,
							value: value,
							sell_value: sell_value,
							interval_count: interval_count,
							recurring_period: recurring_period,
							trail_interval_count: trail_interval_count,
							trail_recurring_period: trail_recurring_period,
							trail_period_enable_val: trail_period_enable_val,
							subscription_expiry_enable: subscription_enable_val,
							subscription_expiry_date: subscription_expiry_date,
							currency: currency,
							checkbox: checkbox
						});
					}
				});
				var checkbox = wrapper.find(".ur-field");
				checkbox.html("");

				for (var i = 0; i < array_value.length; i++) {
					if (array_value[i] !== "") {
						checkbox.append(
							'<label><input value="' +
								array_value[i].label.trim() +
								'" type="radio" ' +
								(array_value[i].checkbox ? "checked" : "") +
								" disabled>" +
								array_value[i].label.trim() +
								" - " +
								array_value[i].currency.trim() +
								" " +
								array_value[i].value.trim() +
								"</label>"
						);
					}
				}

				if ("radio" === this_node.attr("type")) {
					if (this_node.is(":checked")) {
						wrapper
							.find(
								".ur-general-setting-options li:nth(" +
									checked_index +
									') input[data-field="default_value"]'
							)
							.prop("checked", true);
					} else {
						wrapper
							.find(
								".ur-general-setting-options li:nth(" +
									checked_index +
									') input[data-field="default_value"]'
							)
							.prop("checked", false);
					}
				}
			},
			/**
			 * Reflects changes in options of choice fields of field settings into selected field in form builder area.
			 *
			 * @param object $label Options of choice fields from field settings.
			 */
			trigger_general_setting_options: function ($label) {
				var wrapper = $(".ur-selected-item.ur-item-active");
				var index = $label.closest("li").index();
				var field_name = $label.attr("data-field-name");

				if (
					"multiple_choice" === field_name ||
					"subscription_plan" === field_name
				) {
					wrapper
						.find(
							".ur-general-setting-block li:nth(" +
								index +
								') input[name="' +
								$label.attr("name") +
								'"]'
						)
						.val($label.val());
				} else if ("captcha" === $label.attr("data-field-name")) {
					wrapper
						.find(
							".ur-general-setting-block li:nth(" +
								index +
								') input[name="' +
								$label.attr("name") +
								'"]'
						)
						.val($label.val());
				} else {
					wrapper
						.find(
							".ur-general-setting-block li:nth(" +
								index +
								') input[data-field="' +
								$label.attr("data-field") +
								'"]'
						)
						.val($label.val());
				}
				wrapper
					.find(
						".ur-general-setting-block li:nth(" +
							index +
							') input[data-field="default_value"]'
					)
					.val($label.val());

				$label
					.closest("li")
					.find('[data-field="default_value"]')
					.val($label.val());
			},
			/**
			 * Reflects changes in enable selling price field of field settings into selected field in form builder area.
			 *
			 * @param object $label enable selling price field of fields from field settings.
			 */
			trigger_general_setting_selling_price: function ($label) {
				var wrapper = $(".ur-selected-item.ur-item-active");

				wrapper
					.find(".ur-general-setting-block")
					.find(
						'input[data-field="' + $label.attr("data-field") + '"]'
					)
					.prop("checked", $label.is(":checked"));
			},
			/**
			 * Reflects changes in enable selling price field of field settings into selected field in form builder area.
			 *
			 * @param object $label enable selling price field of fields from field settings.
			 */
			trigger_general_setting_trail_period: function ($label) {
				var wrapper = $(".ur-selected-item.ur-item-active");

				wrapper
					.find(".ur-general-setting-block")
					.find(
						'input[data-field="' + $label.attr("data-field") + '"]'
					)
					.prop("checked", $label.is(":checked"));
			},
			/**
			 * Reflects changes in descriptions field of field settings into selected field in form builder area.
			 *
			 * @param object $label Descriptions field of fields from field settings.
			 */
			trigger_general_setting_description: function ($label) {
				var wrapper = $(".ur-selected-item.ur-item-active");
				wrapper
					.find(".ur-field")
					.find("textarea")
					.attr("description", $label.val());
				wrapper
					.find(".ur-general-setting-block")
					.find(
						'textarea[data-field="' +
							$label.attr("data-field") +
							'"]'
					)
					.val($label.val());
			},
			/**
			 * Reflects changes in placeholder field of field settings into selected field in form builder area.
			 *
			 * @param object $label Placeholder field of fields from field settings.
			 */
			trigger_general_setting_placeholder: function ($label) {
				var wrapper = $(".ur-selected-item.ur-item-active");
				wrapper
					.find(".ur-field")
					.find("input")
					.attr("placeholder", $label.val());
				wrapper
					.find(".ur-general-setting-block")
					.find(
						'input[data-field="' + $label.attr("data-field") + '"]'
					)
					.val($label.val());
			},
			/**
			 * Reflects changes in required field of field settings into selected field in form builder area.
			 *
			 * @param object $label Required field of fields from field settings.
			 */
			trigger_general_setting_required: function ($label) {
				var wrapper = $(".ur-selected-item.ur-item-active");
				wrapper
					.find(".ur-general-setting-block")
					.find(
						'input[data-field="' + $label.attr("data-field") + '"]'
					)
					.prop("checked", $label.is(":checked"));

				wrapper
					.find(".ur-label")
					.find("label")
					.find("span:contains(*)")
					.remove();

				if ($label.is(":checked")) {
					wrapper
						.find(".ur-label")
						.find("label")
						.append('<span style="color:red">*</span>');
				}
			},
			/**
			 * Reflects changes in required field of field settings into selected field in form builder area.
			 *
			 * @param object $label Required field of fields from field settings.
			 */
			trigger_general_setting_date_format: function ($label) {
				var wrapper = $(".ur-selected-item.ur-item-active");
				wrapper
					.find(".ur-field")
					.find("input")
					.attr("placeholder", $label.val());
			},
			/**
			 * Reflects changes in hide label field of field settings into selected field in form builder area.
			 *
			 * @param object $label Hide label field of fields from field settings.
			 */
			trigger_general_setting_hide_label: function ($label) {
				var wrapper = $(".ur-selected-item.ur-item-active");

				wrapper
					.find(".ur-general-setting-block")
					.find(
						'input[data-field="' + $label.attr("data-field") + '"]'
					)
					.prop("checked", $label.is(":checked"));

				if ($label.is(":checked")) {
					wrapper.find(".ur-label").find("label").hide();
				} else {
					wrapper.find(".ur-label").find("label").show();
				}
			},

			/**
			 * Reflects changes in hide advance settings of field settings into selected field in form builder area.
			 *
			 * @param object $label Advance settings of fields from field settings.
			 */
			trigger_advance_setting: function ($this_node, node_type) {
				var wrapper = $(".ur-selected-item.ur-item-active");
				var this_node_id = $this_node.attr("data-id");
				var hidden_node = wrapper
					.find(".ur-advance-setting-block")
					.find('[data-id="' + this_node_id + '"]');

				switch (node_type) {
					case "input":
						if ($this_node.attr("type") === "checkbox") {
							hidden_node.prop(
								"checked",
								$this_node.is(":checked")
							);
						} else {
							hidden_node.val($this_node.val());
						}
						break;
					case "select":
						hidden_node.find("option").prop("selected", false);

						if ($this_node.prop("multiple")) {
							var selected_options = $this_node.val();
							hidden_node.val(selected_options);
						} else {
							hidden_node
								.find(
									'option[value="' + $this_node.val() + '"]'
								)
								.prop("selected", true);
						}
						break;
					case "textarea":
						hidden_node.val($this_node.val());
						URFormBuilder.render_text_area($this_node.val());
						break;
				}
			},
			/**
			 * Sweetalert2 alert popup.
			 *
			 * @param string message Message to be shown in popup.
			 * @param object options Options for popup.
			 */
			ur_alert: function (message, options) {
				if ("undefined" === typeof options) {
					options = {};
				}
				Swal.fire({
					icon: "error",
					title: options.title,
					text: message,
					customClass:
						"user-registration-swal2-modal user-registration-swal2-modal--center"
				});
			},
			/**
			 * Handle all actions performed on the choice field options.
			 */
			init_choice_field_options: function () {
				//Handle Sorting for options of choice fields
				$(document).on(
					"sortstop",
					".ur-options-list",
					function (event, ui) {
						var $this_obj = $(this);
						URFormBuilder.handle_options_sort($this_obj);
					}
				);

				//Handle adding of options in choice fields on click.
				$(document).on("click", ".ur-options-list .add", function (e) {
					e.preventDefault();
					var $this = $(this);
					URFormBuilder.add_choice_field_option($this);
				});

				//Handle remmoval of options in choice fields on click.
				$(document).on(
					"click",
					".ur-options-list .remove",
					function (e) {
						e.preventDefault();
						var $this = $(this);
						URFormBuilder.remove_choice_field_option($this);
					}
				);
			},
			/**
			 * Clone options and render them for choice fields in form builder.
			 *
			 * @param object $this_obj The field option to clone and render.
			 */
			handle_options_sort: function ($this_obj) {
				URFormBuilder.ur_clone_options($this_obj);
				if (
					$this_obj
						.closest(".ur-general-setting-block")
						.hasClass("ur-general-setting-radio")
				) {
					URFormBuilder.render_radio($this_obj);
				} else if (
					$this_obj
						.closest(".ur-general-setting-block")
						.hasClass("ur-general-setting-checkbox")
				) {
					URFormBuilder.render_check_box($this_obj);
				} else if (
					$this_obj
						.closest(".ur-general-setting-block")
						.hasClass("ur-general-setting-multiple_choice")
				) {
					URFormBuilder.render_multiple_choice($this_obj);
				} else if (
					$this_obj
						.closest(".ur-general-setting-block")
						.hasClass("ur-general-setting-subscription_plan")
				) {
					URFormBuilder.render_subscription_plan($this_obj);
				}
			},
			/**
			 * Clone choice field options.
			 *
			 * @param object $this_obj The field option to clone.
			 */
			ur_clone_options: function ($this_obj) {
				var cloning_options = $this_obj.clone(true, true);
				var wrapper = $(".ur-selected-item.ur-item-active");
				var cloning_element = wrapper.find(
					".ur-general-setting-options .ur-options-list"
				);
				cloning_element.html("");
				cloning_element.replaceWith(cloning_options);
			},
			/**
			 * Add a new option in choice field when called.
			 *
			 * @param object $this_obj The field option to add.
			 * @param string value The value of the option.
			 */
			add_choice_field_option: function ($this, value) {
				$this_obj = $(this);
				var $wrapper = $(".ur-selected-item.ur-item-active"),
					this_index = $this.closest("li").index(),
					cloning_element = $this.closest("li").clone(true, true);
				cloning_element
					.find("input.ur-subscription-expiry-date")
					.attr(
						"data-id",
						"expiry-date-index-" +
							this_index +
							Math.floor(Math.random() * 900) +
							100
					);
				cloning_element
					.find('input[data-field="options"]')
					.val(typeof value !== "undefined" ? value : "");
				cloning_element
					.find('input[data-field="default_value"]')
					.prop("checked", false);
				cloning_element.find('select[data-field="options"]').val("");
				cloning_element.find(".ur-thumbnail-image img").attr("src", "");

				if (
					$this.closest(".ur-general-setting-image-captcha-options")
						.length > 0
				) {
					URFormBuilder.handle_add_image_captcha_group(
						$this,
						$wrapper
					);
				} else {
					var this_index = $this.closest("li").index();
					cloning_element = $this.closest("li").clone(true, true);
					cloning_element
						.find('input[data-field="options"]')
						.val(typeof value !== "undefined" ? value : "");
					cloning_element
						.find('input[data-field="default_value"]')
						.prop("checked", false);
					cloning_element
						.find('select[data-field="options"]')
						.val("");
					cloning_element
						.find(".ur-thumbnail-image img")
						.attr("src", "");

					$this.closest("li").after(cloning_element);
					$wrapper
						.find(
							".ur-general-setting-options .ur-options-list > li:nth( " +
								this_index +
								" )"
						)
						.after(cloning_element.clone(true, true));

					if (
						$this
							.closest(".ur-general-setting-block")
							.hasClass("ur-general-setting-radio")
					) {
						URFormBuilder.render_radio($this);
					} else if (
						$this
							.closest(".ur-general-setting-block")
							.hasClass("ur-general-setting-checkbox")
					) {
						URFormBuilder.render_check_box($this);
					} else if (
						$this
							.closest(".ur-general-setting-block")
							.hasClass("ur-general-setting-multiple_choice")
					) {
						URFormBuilder.render_multiple_choice($this);
					}
				}

				$(document.body).trigger("ur_field_option_changed", [
					{ action: "add", wrapper: $wrapper },
					URFormBuilder,
					$this
				]);
			},
			/**
			 * Remove an option in choice field when called.
			 *
			 * @param object $this_obj The field option to remove.
			 */
			remove_choice_field_option: function ($this) {
				var $parent_ul = $this.closest("ul"),
					$any_siblings = $parent_ul.find("li"),
					$wrapper = $(".ur-selected-item.ur-item-active"),
					this_index = $this.closest("li").index();

				if ($parent_ul.find("li").length > 1) {
					if (
						$this.closest(
							".ur-general-setting-image-captcha-options"
						).length > 0
					) {
						URFormBuilder.handle_remove_image_captcha_group(
							$this,
							$wrapper,
							this_index
						);
					} else {
						$wrapper
							.find(
								".ur-general-setting-options .ur-options-list > li:nth( " +
									this_index +
									" )"
							)
							.remove();
						$this.closest("li").remove();
					}

					if (
						$any_siblings
							.closest(".ur-general-setting-block")
							.hasClass("ur-general-setting-radio")
					) {
						URFormBuilder.render_radio($any_siblings);
					} else if (
						$any_siblings
							.closest(".ur-general-setting-block")
							.hasClass("ur-general-setting-checkbox")
					) {
						URFormBuilder.render_check_box($any_siblings);
					} else if (
						$any_siblings
							.closest(".ur-general-setting-block")
							.hasClass("ur-general-setting-multiple_choice")
					) {
						URFormBuilder.render_multiple_choice($any_siblings);
					} else if (
						$any_siblings
							.closest(".ur-general-setting-block")
							.hasClass("ur-general-setting-subscription_plan")
					) {
						URFormBuilder.render_subscription_plan($any_siblings);
					}
				}

				$(document.body).trigger("ur_field_option_changed", [
					{ action: "remove", wrapper: $wrapper },
					URFormBuilder,
					$this
				]);
			},

			handle_add_image_captcha_group: function ($this, $wrapper) {
				var this_index = parseInt($this.attr("data-last-group")),
					next_index = this_index + 1;
				((captcha_unique = $this
					.closest("ul")
					.attr("data-unique-captcha")),
					(cloning_element = $this
						.closest("ul")
						.find('li[data-group="' + this_index + '"]')
						.clone(true, true)),
					(cloning_element_icons =
						cloning_element.find(".icon-wrap")));

				cloning_element.attr("data-group", next_index);
				cloning_element
					.find(".ur-type-captcha-icon-tag")
					.attr(
						"name",
						"ur_general_setting[captcha_image][" +
							next_index +
							"][icon_tag]"
					);
				cloning_element.find(".ur-type-captcha-icon-tag").val("");
				cloning_element
					.find(".ur-type-captcha-icon-tag")
					.attr("placeholder", "Icon Tag");

				$.each(
					cloning_element_icons,
					function (icon_index, icon_element) {
						var next_icon_index = icon_index + 1;
						$(icon_element)
							.find(".ur-captcha-icon-radio")
							.attr(
								"name",
								"ur_general_setting[captcha_image][" +
									next_index +
									"][correct_icon][" +
									captcha_unique +
									"]"
							);
						$(icon_element)
							.find(".ur-captcha-icon-radio")
							.prop("checked", false);
						$(icon_element)
							.find(".captcha-icon")
							.attr(
								"name",
								"ur_general_setting[captcha_image][" +
									next_index +
									"][icon-" +
									next_icon_index +
									"]"
							);
						$(icon_element).find(".captcha-icon").val("");
						$(icon_element)
							.find(".captcha-icon")
							.siblings("span")
							.attr("class", "");
						$(icon_element)
							.find(".dashicons-picker")
							.attr("data-icon-key", "icon-" + next_icon_index);
						$(icon_element)
							.find(".dashicons-picker")
							.attr("data-group-id", next_index);
					}
				);

				$this
					.closest("ul")
					.find('li[data-group="' + this_index + '"]')
					.after(cloning_element);
				$this.attr("data-last-group", next_index);
				$wrapper
					.find(
						".ur-general-setting-image-captcha-options .ur-options-list > li:nth( " +
							this_index +
							" )"
					)
					.after(cloning_element.clone(true, true));
				$wrapper
					.find(
						".ur-general-setting-image-captcha-options .ur-options-list .add-icon-group"
					)
					.attr("data-last-group", next_index);
			},

			handle_remove_image_captcha_group: function (
				$this,
				$wrapper,
				this_index
			) {
				$wrapper
					.find(
						".ur-general-setting-image-captcha-options .ur-options-list > li:nth( " +
							this_index +
							" )"
					)
					.remove();

				var next_li_group = $wrapper.find(
						".ur-general-setting-image-captcha-options .ur-options-list li.ur-custom-captcha"
					),
					settings_li_group = $this
						.closest("li")
						.siblings(".ur-custom-captcha");

				$this
					.closest("ul.ur-options-list")
					.find(".add-icon-group")
					.attr(
						"data-last-group",
						parseInt(settings_li_group.length) - 1
					);
				$this.closest("li").remove();

				var captcha_unique = $this
					.closest("ul.ur-options-list")
					.attr("data-unique-captcha");
				$.each(next_li_group, function (li_index, li_group) {
					$(li_group).attr("data-group", li_index);
					$(li_group)
						.find(".ur-type-captcha-icon-tag")
						.attr(
							"name",
							"ur_general_setting[captcha_image][" +
								li_index +
								"][icon_tag]"
						);

					var icon_wrap = $(li_group).find(".icon-wrap");

					$.each(icon_wrap, function (icon_index, icon_element) {
						var next_icon_index = icon_index + 1;
						$(icon_element)
							.find(".captcha-icon")
							.attr(
								"name",
								"ur_general_setting[captcha_image][" +
									li_index +
									"][icon-" +
									next_icon_index +
									"]"
							);
						$(icon_element)
							.find(".ur-captcha-icon-radio")
							.attr(
								"name",
								"ur_general_setting[captcha_image][" +
									li_index +
									"][correct_icon][" +
									captcha_unique +
									"]"
							);
						$(icon_element)
							.find(".dashicons-picker")
							.attr("data-icon-key", "icon-" + next_icon_index);
						$(icon_element)
							.find(".dashicons-picker")
							.attr("data-group-id", li_index);
					});
				});
				$.each(settings_li_group, function (li_index, li_group) {
					$(li_group).attr("data-group", li_index);
					$(li_group)
						.find(".ur-type-captcha-icon-tag")
						.attr(
							"name",
							"ur_general_setting[captcha_image][" +
								li_index +
								"][icon_tag]"
						);

					var icon_wrap = $(li_group).find(".icon-wrap");

					$.each(icon_wrap, function (icon_index, icon_element) {
						var next_icon_index = icon_index + 1;
						$(icon_element)
							.find(".captcha-icon")
							.attr(
								"name",
								"ur_general_setting[captcha_image][" +
									li_index +
									"][icon-" +
									next_icon_index +
									"]"
							);
						$(icon_element)
							.find(".ur-captcha-icon-radio")
							.attr(
								"name",
								"ur_general_setting[captcha_image][" +
									li_index +
									"][correct_icon][" +
									captcha_unique +
									"]"
							);
						$(icon_element)
							.find(".dashicons-picker")
							.attr("data-icon-key", "icon-" + next_icon_index);
						$(icon_element)
							.find(".dashicons-picker")
							.attr("data-group-id", li_index);
					});
				});

				$wrapper
					.find(
						".ur-general-setting-image-captcha-options .ur-options-list .add-icon-group"
					)
					.attr(
						"data-last-group",
						parseInt(next_li_group.length) - 1
					);
			},
			check_membership_validation: function (data) {
				var validations = [
						"empty_membership_group_status",
						"payment_field_present_status",
						"empty_membership_status"
					],
					is_valid = true;

				for (var i = 0; i < validations.length; i++) {
					var key = validations[i];
					if (
						typeof data.data[key] !== "undefined" &&
						data.data[key][0].validation_status === false
					) {
						is_valid = false;
						URFormBuilder.show_message(
							data.data[key][0].validation_message
						);
						return is_valid;
					}
				}
				return is_valid;
			}
		};

		URFormBuilder.init();

		/**
		 * Load flatpickr and handle changes in date field settings.
		 */
		$(document).ready(function () {
			var date_flatpickrs = {};

			$(document.body).on("click", ".ur-flatpickr-field", function () {
				var field_id = $(this).data("id");
				var date_flatpickr = date_flatpickrs[field_id];

				// Load a flatpicker for the field, if hasn't been loaded.
				if (!date_flatpickr) {
					var formated_date = $(this)
						.closest(".ur-field-item")
						.find("#formated_date")
						.val();

					if (0 < $(".ur-frontend-form").length) {
						var date_selector = $(".ur-frontend-form #" + field_id)
							.attr("type", "text")
							.val(formated_date);
					} else {
						var date_selector = $(
							".woocommerce-MyAccount-content #" + field_id
						)
							.attr("type", "text")
							.val(formated_date);
					}

					$(this).attr(
						"data-date-format",
						date_selector.data("date-format")
					);
					$(this).attr("data-mode", date_selector.data("mode"));
					$(this).attr("data-locale", date_selector.data("locale"));
					$(this).attr(
						"data-min-date",
						date_selector.data("min-date")
					);
					$(this).attr(
						"data-max-date",
						date_selector.data("max-date")
					);
					$(this).attr("data-default-date", formated_date);
					date_flatpickr = $(this).flatpickr({
						disableMobile: true,
						onChange: function (
							selectedDates,
							dateString,
							instance
						) {
							$("#" + field_id).val(dateString);
						},
						onOpen: function (selectedDates, dateStr, instance) {
							instance.set(
								"minDate",
								date_selector.data("min-date")
							);
							instance.set(
								"maxDate",
								date_selector.data("max-date")
							);
						}
					});
					date_flatpickrs[field_id] = date_flatpickr;
				}

				if (date_flatpickr) {
					date_flatpickr.open();
				}
			});
		});
		/**
		 * For toggling headings.
		 */
		$(document).on("click", ".ur-toggle-heading", function () {
			if ($(this).hasClass("closed")) {
				$(this).removeClass("closed");
			} else {
				$(this).addClass("closed");
			}
			$(this)
				.parent(".user-registration-field-option-group")
				.toggleClass("closed")
				.toggleClass("open");
			var field_list = $(this).find(" ~ .ur-registered-list")[0];
			$(field_list).slideToggle();

			// For `Field Options` section
			$(this).siblings(".ur-toggle-content").stop().slideToggle();
		});

		$(document.body)
			.on("init_field_options_toggle", function () {
				$(".user-registration-field-option-group.closed").each(
					function () {
						$(this).find(".ur-toggle-content").hide();
					}
				);
			})
			.trigger("init_field_options_toggle");

		/**
		 * For toggling quick links content.
		 */

		$(document.body).on("click", ".ur-quick-links-content", function (e) {
			e.stopPropagation();
		});
		$(document.body).on("click", ".ur-button-quick-links", function (e) {
			e.stopPropagation();
			$(".ur-quick-links-content").toggle();
		});
		$(document.body).on("click", function (e) {
			if (!$(".ur-quick-links-content").is(":hidden")) {
				$(".ur-quick-links-content").toggle();
			}
		});

		$(document)
			.find(
				"input[name='user_registration_form_setting_minimum_password_strength']"
			)
			.each(function () {
				$(this).on("click", function () {
					$(this)
						.closest(".ur-radio-group-list")
						.find(".active")
						.removeClass("active");
					$(this)
						.closest(".ur-radio-group-list")
						.find(
							"input[name='user_registration_form_setting_minimum_password_strength']"
						)
						.prop("checked", false);
					$(this)
						.closest(".ur-radio-group-list--item")
						.addClass("active");

					$(this).prop("checked", true);
				});
			});

		$(document).on("click", function () {
			if ($(document).find(".ur-smart-tags-list").is(":visible")) {
				$(".ur-smart-tags-list").hide();
			}
		});

		$(".ur-smart-tags-list").hide();

		$(document.body).on(
			"click",
			".ur-smart-tags-list-button",
			function (e) {
				e.stopPropagation();
				$(".ur-smart-tags-list").hide();
				$(this).parent().find(".ur-smart-tags-list").toggle("show");
			}
		);

		$(document.body).on("click", ".ur-select-smart-tag", function (event) {
			event.preventDefault();
			var smart_tag;
			input_value = $(this)
				.parent()
				.parent()
				.parent()
				.find("input")
				.val();
			smart_tag = $(this).data("key");
			input_value = smart_tag;
			var inputElement = $(this).parent().parent().parent().find("input"),
				advanceFieldData = inputElement.data("advance-field"),
				fieldData = inputElement.data("field"),
				field_name =
					advanceFieldData !== undefined
						? advanceFieldData
						: fieldData;
			update_input(field_name, input_value);

			$(this).parent().parent().parent().find("input").val(input_value);
			$(document.body).find(".ur-smart-tags-list").hide();
		});

		$(document.body).on(
			"change",
			".ur_advance_setting.ur-settings-default-value",
			function () {
				input_value = $(this).val();
				field_name = $(this).data("advance-field");
				update_input(input_value);
			}
		);
		$(document.body).on(
			"change",
			".ur-general-setting.ur-general-setting-hidden-value input",
			function () {
				input_value = $(this).val();
				field_name = $(this).data("field");
				update_input(input_value);
			}
		);

		$(document.body).on(
			"change",
			".ur_advance_setting.ur-settings-pattern_value",
			function () {
				input_value = $(this).val();
				field_name = $(this).data("advance-field");
				update_input(input_value);
			}
		);

		/**
		 * For update the default value.
		 */
		function update_input(field_name, input_value) {
			active_field = $(".ur-item-active");
			target_input_field = $(active_field).find(
				".user-registration-field-option-group.ur-advance-setting-block"
			);
			ur_toggle_content = target_input_field.find(
				".ur-advance-setting.ur-advance-default_value"
			);
			target_input = $(ur_toggle_content).find(
				'input[data-advance-field="' + field_name + '"]'
			);
			target_textarea = $(ur_toggle_content).find(
				'input[data-advance-field="' + field_name + '"]'
			);

			target_input_hidden_field = $(active_field).find(
				".ur-general-setting-block"
			);
			ur_toggle_hidden_content = target_input_hidden_field.find(
				".ur-general-setting.ur-general-setting-hidden-value"
			);
			target_hidden_input = $(ur_toggle_hidden_content).find(
				'input[data-field="' + field_name + '"]'
			);
			// pattern value
			ur_toggle_pattern_content = target_input_field.find(
				".ur-advance-setting.ur-advance-pattern_value"
			);
			target_pattern_input = $(ur_toggle_pattern_content).find(
				'input[data-advance-field="' + field_name + '"]'
			);
			target_input.val(input_value);
			target_textarea.val(input_value);
			target_hidden_input.val(input_value);
			target_pattern_input.val(input_value);
		}

		/**
		 * Displays a feature notice if user try to delete the password_field.
		 */
		function show_feature_notice(field_key, label) {
			var isPro = user_registration_form_builder_data.isPro;
			var cancelBtn = true;
			if ("user_pass" === field_key) {
				if (isPro) {
					var description_message =
						user_registration_form_builder_data.i18n_admin
							.i18n_auto_generate_password;
					var confirmButtonText =
						user_registration_form_builder_data.i18n_admin
							.i18n_learn_more;
					var btn_link =
						user_registration_form_builder_data.ur_remove_password_field_link;
				} else {
					var description_message =
						user_registration_form_builder_data.i18n_admin
							.i18n_delete_pass_available_in_pro;
					var confirmButtonText = ur_setup_params.upgrade_button;
					var btn_link =
						user_registration_form_builder_data.ur_upgrade_plan_link;
				}
			} else if ("user_email" === field_key) {
				var description_message =
					user_registration_form_builder_data.i18n_admin
						.i18n_default_cannot_delete_message;
				var confirmButtonText =
					user_registration_form_builder_data.i18n_admin.i18n_ok;
				cancelBtn = false;
			} else {
				var description_message =
					user_registration_form_builder_data.i18n_admin
						.i18n_user_email_and_password_fields_are_required_to_create_a_registration_form;
				var confirmButtonText =
					user_registration_form_builder_data.i18n_admin.i18n_ok;
				cancelBtn = false;
			}

			var icon = '<i class="dashicons dashicons-trash" ></i>';
			if (label !== "") {
				var title_message =
					user_registration_form_builder_data.i18n_admin
						.i18n_this_field_is_required;
				var title =
					icon +
					'<span class="user-registration-swal2-modal__title">' +
					label +
					title_message;
			} else {
				var title_message =
					user_registration_form_builder_data.i18n_admin
						.i18n_cannot_delete_row;
				var title =
					icon +
					'<span class="user-registration-swal2-modal__title">' +
					title_message;
			}

			Swal.fire({
				customClass:
					"user-registration-swal2-modal user-registration-swal2-modal--centered user-registration-upgrade",
				title: title,
				text: description_message,
				showCancelButton: cancelBtn,
				cancelButtonText:
					user_registration_form_builder_data.i18n_admin
						.i18n_choice_cancel,
				showConfirmButton: true,
				confirmButtonText: confirmButtonText,
				confirmButtonColor: "#475bb2 !important"
			}).then(function (result) {
				if (result.isConfirmed && btn_link) {
					window.open(btn_link, "_blank");
				}
			});
		}
		/**
		 * This block of code is for the "Selected Countries" option of "Country" field
		 *
		 * Doc: https://select2.org/
		 * Ref: https://jsfiddle.net/Lkkm2L48/7/
		 */
		var SelectionAdapter, DropdownAdapter;
		$.fn.select2.amd.require(
			[
				"select2/selection/single",
				"select2/selection/placeholder",
				"select2/dropdown",
				"select2/dropdown/search",
				"select2/dropdown/attachBody",
				"select2/utils",
				"select2/selection/eventRelay"
			],
			function (
				SingleSelection,
				Placeholder,
				Dropdown,
				DropdownSearch,
				AttachBody,
				Utils,
				EventRelay
			) {
				// Add placeholder which shows current number of selections
				SelectionAdapter = Utils.Decorate(SingleSelection, Placeholder);

				// Allow to flow/fire events
				SelectionAdapter = Utils.Decorate(SelectionAdapter, EventRelay);

				// Add search box in dropdown
				DropdownAdapter = Utils.Decorate(Dropdown, DropdownSearch);

				// Add attach-body in dropdown
				DropdownAdapter = Utils.Decorate(DropdownAdapter, AttachBody);

				/**
				 * Create UnSelectAll Adapter for unselect-all button
				 *
				 * Ref: http://jsbin.com/seqonozasu/1/edit?html,js,output
				 */
				function UnselectAll() {}
				UnselectAll.prototype.render = function (decorated) {
					var self = this;
					var $rendered = decorated.call(this);
					var $unSelectAllButton = $(
						'<button class="button button-secondary button-medium ur-unselect-all-countries-button" type="button">Unselect All</button>'
					);

					$unSelectAllButton.on("click", function () {
						self.$element.val([]);
						self.$element.trigger("change");
						self.trigger("close");
					});
					$rendered
						.find(".select2-dropdown")
						.prepend($unSelectAllButton);

					return $rendered;
				};

				// Add unselect all button in dropdown
				DropdownAdapter = Utils.Decorate(DropdownAdapter, UnselectAll);

				/**
				 * Create SelectAll Adapter for select-all button
				 *
				 * Ref: http://jsbin.com/seqonozasu/1/edit?html,js,output
				 */
				function SelectAll() {}
				SelectAll.prototype.render = function (decorated) {
					var self = this;
					var $rendered = decorated.call(this);
					var $selectAllButton = $(
						'<button class="button button-secondary button-medium ur-select-all-countries-button" type="button">Select All</button>'
					);

					$selectAllButton.on("click", function () {
						var $options = self.$element.find("option");
						var values = [];

						$options.each(function () {
							values.push($(this).val());
						});
						self.$element.val(values);
						self.$element.trigger("change");
						self.trigger("close");
					});
					$rendered
						.find(".select2-dropdown")
						.prepend($selectAllButton);

					return $rendered;
				};

				// Add select all button in dropdown
				DropdownAdapter = Utils.Decorate(DropdownAdapter, SelectAll);
			}
		);

		// Prevent invalid input for Max Upload Size setting and Maximum upload limit input.

		$(document.body).on(
			"input",
			'[data-advance-field="max_upload_size"], [data-field="max_files"]',
			function () {
				var $this = $(this);
				var inputValue = $this.val();
				inputValue = inputValue.replace(/[^0-9]/g, "");
				$this.val(inputValue);
			}
		);
		// Make a data-id unique for flatpicker.
		$(document).on(
			"click",
			".ur-input-type-subscription_plan",
			function () {
				$(this)
					.next(".ur-general-setting-subscription_plan")
					.find(".ur-subscription-plan")
					.each(function (index) {
						var expiry_date_id = $(this).find(
							".ur-subscription-expiry-date"
						);
						var uniqueId = "expiry-date-index-" + index;
						expiry_date_id.attr("data-id", uniqueId);
					});
			}
		);

		$(document.body).on(
			"focusout",
			'[data-advance-field="max_upload_size"], [data-field="max_files"]',
			function () {
				var $this = $(this);
				var inputValue = $this.val();

				if ("" === inputValue || 0 === parseInt(inputValue)) {
					inputValue = "";
				} else {
					inputValue = parseInt(inputValue); // Remove prefixing zeros(0).
				}

				$this.val(inputValue);
			}
		);

		$(document).on(
			"change",
			"#user_registration_form_setting_enable_recaptcha_support",
			function (e) {
				var $this = $(this);
				if (
					user_registration_form_builder_data.no_captcha_set &&
					$this.is(":checked")
				) {
					// Immediately uncheck the checkbox
					$this.prop("checked", false);
					// Hide the dropdown since checkbox is unchecked
					$(
						"#user_registration_form_setting_configured_captcha_type_field"
					).hide();
					// Show error message using existing function
					URFormBuilder.show_message(
						user_registration_form_builder_data.i18n_captcha_not_set_error,
						"error"
					);
					e.stopImmediatePropagation();
					return false;
				}
			}
		);
	});
})(jQuery, window.user_registration_form_builder_data);
