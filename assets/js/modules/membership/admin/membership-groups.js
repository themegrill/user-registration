/**UR_Snackbar**/
(function ($, urmg_data) {
	if (typeof UR_Snackbar !== "undefined") {
		var snackbar = new UR_Snackbar();
	}
	var membership_group_object = {
		init: function () {
			membership_group_object.bind_ui_actions();
		},

		if_empty: function (value, _default) {
			if (null === value || undefined === value || "" === value) {
				return _default;
			}
			return value;
		},
		/**
		 * Enable/Disable save buttons i.e. 'Save' button and 'Save as Draft' button.
		 *
		 * @param {Boolean} disable Whether to disable or enable.
		 */
		toggleSaveButtons: function (disable) {
			disable = membership_group_object.if_empty(disable, true);
			$(".ur-membership-group-save-btn").prop("disabled", !!disable);
		},
		/**
		 * Show success message using snackbar.
		 *
		 * @param {String} message Message to show.
		 */
		show_success_message: function (message) {
			if (snackbar) {
				snackbar.add({
					type: "success",
					message: message,
					duration: 5
				});
				return true;
			}
			return false;
		},
		/**
		 * Show failure message using snackbar.
		 *
		 * @param {String} message Message to show.
		 */
		show_failure_message: function (message) {
			if (snackbar) {
				snackbar.add({
					type: "failure",
					message: message,
					duration: 6
				});
				return true;
			}
			return false;
		},
		/**
		 * Append spinner element.
		 *
		 * @param {jQuery} $element
		 */
		append_spinner: function ($element) {
			if ($element && $element.append) {
				var spinner = '<span class="ur-spinner is-active"></span>';

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
				$element.find(".ur-spinner").remove();
				return true;
			}
			return false;
		},

		validate_membership_group_form: function () {
			var form = $("#ur-membership-group-create-form"),
				main_fields = form.find(
					"#ur-membership-main-fields .urmg-input"
				),
				no_errors = true;
			//main fields validation
			main_fields = Object.values(main_fields).reverse().slice(2);

			var result = membership_group_object.regular_validation(
				main_fields,
				true
			);

			if (!result) {
				return false;
			}
			return no_errors;
		},

		prepare_membership_data: function () {
			var post_data = {},
				post_meta_data = {},
				form = $("#ur-membership-group-create-form");
			post_data = {
				name: form.find("#ur-input-type-membership-group-name").val(),
				description: form
					.find("#ur-input-type-membership-group-description")
					.val(),
				status: $("#ur-membership-group-status").prop("checked")
			};
			if (urmg_data.membership_group_id) {
				post_data.ID = urmg_data.membership_group_id;
			}

			post_meta_data.memberships = form
				.find("#ur-input-type-membership-group-memberships")
				.select2("val");

			var multiple_enabled = $(
					'input[name="ur_membership_enable_multiple_memberships"]'
				).is(":checked"),
				upgrade_enabled = $(
					'input[name="ur_membership_enable_upgrade"]:checked'
				).is(":checked");
			post_meta_data.mode = multiple_enabled
				? "multiple"
				: upgrade_enabled
				? "upgrade"
				: "";

			if ("upgrade" === post_meta_data.mode) {
				post_meta_data.upgrade_type = $(
					'input[name="ur_membership_upgrade_type"]:checked'
				).val();
				post_meta_data.upgrade_path = $(
					'input[name="ur_membership_upgrade_path"]'
				).val();
				post_meta_data.upgrade_order = $(
					'input[name="ur_membership_upgrade_order"]'
				).val();
			}

			return {
				post_data: post_data,
				post_meta_data: post_meta_data
			};
		},
		//regular required validation
		regular_validation: function (inputs, no_errors) {
			inputs.every(function (item) {
				var $this = $(item),
					value = $this.val(),
					is_required = $this.attr("required"),
					name = $this.data("key-name");
				if (
					is_required &&
					(value === "" || value === null || value.length < 1)
				) {
					no_errors = false;
					membership_group_object.show_failure_message(
						urmg_data.labels.i18n_error +
							"! " +
							name +
							" " +
							urmg_data.labels.i18n_field_is_required
					);
					return false;
				}
				return true;
			});
			return no_errors;
		},
		create_membership_group: function ($this) {
			membership_group_object.toggleSaveButtons(true);
			membership_group_object.append_spinner($this);
			if (this.validate_membership_group_form()) {
				var prepare_membership_groups_data =
					this.prepare_membership_data();

				this.send_data(
					{
						action: "user_registration_membership_create_membership_group",
						membership_groups_data: JSON.stringify(
							prepare_membership_groups_data
						)
					},
					{
						success: function (response) {
							if (response.success) {
								urmg_data.membership_group_id =
									response.data.membership_group_id;
								$this.text(urmg_data.labels.i18n_save);
								membership_group_object.show_success_message(
									response.data.message
								);

								// var current_url = $(location).attr('href');
								//
								// current_url += '&post_id=' + urmg_data.membership_group_id;
								$(location).attr(
									"href",
									urmg_data.membership_group_url
								);
							} else {
								membership_group_object.show_failure_message(
									response.data.message
								);
							}
						},
						failure: function (xhr, statusText) {
							membership_group_object.show_failure_message(
								urmg_data.labels.network_error +
									"(" +
									statusText +
									")"
							);
						},
						complete: function () {
							membership_group_object.remove_spinner($this);
							membership_group_object.toggleSaveButtons(false);
						}
					}
				);
			} else {
				membership_group_object.remove_spinner($this);
				membership_group_object.toggleSaveButtons(false);
			}
		},
		/**
		 * Binds UI actions to the membership group page.
		 *
		 * Specifically, this function:
		 *
		 * 1. Listens for changes in the membership group select box, and
		 *    fetches the memberships for the selected group.
		 * 2. Listens for clicks on the membership group save button, and
		 *    triggers the creation of a membership group.
		 * 3. Initializes the select2 for the membership group select box.
		 * 4. Listens for clicks on the bulk action buttons, and
		 *    triggers the bulk deletion of membership groups.
		 */
		bind_ui_actions: function () {
			$(document).on(
				"change",
				"#ur-setting-form .ur-general-setting-membership_listing_option select",
				function () {
					var $this = $(this),
						group_select_field = $(
							"#ur-setting-form .ur-general-setting-membership_group"
						),
						active_memberships_field = $(
							"#ur-setting-form .ur-general-setting-membership_active_memberships"
						),
						$formSelect = active_memberships_field.find("select");
					group_select_field
						.hide()
						.find("select")
						.prop("selectedIndex", 0)
						.trigger("change");
					// Preserve selected memberships from form select before hiding (for restore when switching back)
					var preservedVal = $formSelect.length ? ($formSelect.val() || []) : [];
					if (!Array.isArray(preservedVal)) {
						preservedVal = preservedVal ? [].concat(preservedVal) : [];
					}
					active_memberships_field.hide();
					$(
						".ur-general-setting-membership_listing_option select"
					).val($this.val());

					if ($this.val() === "group") {
						group_select_field.show();
					} else if ($this.val() === "selected") {
						active_memberships_field.show();
						// Restore selected memberships from grid item (source of truth) or preserved value
						var $gridSelect = $(
							".ur-item-active .ur-general-setting-membership_active_memberships select"
						);
						var restoreVal = $gridSelect.length && ($gridSelect.val() || []).length
							? ($gridSelect.val() || [])
							: preservedVal;
						if (!Array.isArray(restoreVal)) {
							restoreVal = restoreVal ? [].concat(restoreVal) : [];
						}
						if (restoreVal.length && $formSelect.length) {
							$formSelect.val(restoreVal);
							if ($formSelect.hasClass("select2-hidden-accessible")) {
								$formSelect.trigger("change");
							}
							// Keep grid in sync when restoring from preserved value
							if ($gridSelect.length) {
								$gridSelect.val(restoreVal);
							}
						}
					} else {
						membership_group_object.fetch_memberships(-1);
					}
				}
			);
			$(document).on(
				"change",
				'[data-field-group="payments"] input[name^="user_registration_enable_"]',
				function () {
					var $checkboxes = $(
						"[data-field-group='payments'] input[name^='user_registration_enable_']"
					);
					if ($checkboxes.is(":checked")) {
						// disable membership field.
						$membershipField = $(".ur-registered-list").find(
							"li[data-field-id='user_registration_membership']"
						);
						$membershipField.draggable("disable");
						$membershipField.addClass(
							"ur-membership-field-disabled"
						);
						$membershipField.addClass("ur-locked-field");
					} else {
						// enable membership field.
						$membershipField = $(".ur-registered-list").find(
							"li[data-field-id='user_registration_membership']"
						);
						$membershipField.draggable("enable");
						$membershipField.removeClass(
							"ur-membership-field-disabled"
						);
						$membershipField.removeClass("ur-locked-field");
					}
				}
			);
			// listen for changes in the membership group select box
			$(document).on(
				"change",
				"#ur-setting-form .ur-general-setting-membership_group select",
				function () {
					var $this = $(this),
						group_id = Number($this.val());

					$(".ur-general-setting-membership_group select").val(
						group_id
					);
					membership_group_object.fetch_memberships(group_id);
				}
			);
			// listen for changes in the active memberships multiselect
			$(document).on(
				"change",
				"#ur-setting-form .ur-general-setting-membership_active_memberships select",
				function () {
					var val = $(this).val();
					// Sync value to grid item so it is saved when form is submitted
					var $gridSelect = $(".ur-item-active .ur-general-setting-membership_active_memberships select");
					if ($gridSelect.length && val != null) {
						$gridSelect.val(Array.isArray(val) ? val : [].concat(val));
					}
					membership_group_object.fetch_selected_memberships();
				}
			);
			// listen for clicks on the membership group save button
			$(document).on(
				"click",
				".ur-membership-group-save-btn",
				function (e) {
					e.preventDefault();
					e.stopPropagation();

					var $this = $(this);
					membership_group_object.create_membership_group($this);
				}
			);
			// initialize select2
			$("#ur-input-type-membership-group-memberships").select2({
				placeholder: "Select memberships.",
				minimumResultsForSearch: -1,
				multiple: true
			});
			// listen for clicks on the bulk action buttons
			$("#membership-group-list #doaction,#doaction2").on(
				"click",
				function (e) {
					e.preventDefault();
					e.stopPropagation();
					var form = $("#membership-group-list"),
						selectedAction = form
							.find(
								"select#bulk-action-selector-top option:selected"
							)
							.val();
					switch (selectedAction) {
						case "delete":
							membership_group_object.handle_bulk_delete_action(
								form
							);
							break;
						default:
							break;
					}
				}
			);
			//delete membership
			$(".delete-membership-groups").on("click", function (e) {
				e.preventDefault();
				e.stopPropagation();
				membership_group_object.delete_single_membership_group($(this));
			});
			$(document).on("ur_new_field_created", function () {
				var paypal_settings = $("#paypal-standard-settings"),
					stripe_settings = $("#stripe-settings"),
					group_select_field = $(
						"#ur-setting-form .ur-general-setting-membership_group"
					);
				paypal_settings.show();
				stripe_settings.show();
				group_select_field.hide();
				if (
					$(".ur-selected-inputs").find(
						'div[data-field-key="membership"]'
					).length
				) {
					user_registration_form_builder_data.form_has_membership_field = true;
					paypal_settings.addClass("disabled");
					stripe_settings.addClass("disabled");
					//➔ disable payment form settings on membership field added to the form.
					var payment_form_settings = $(
						"#ur-tab-field-settings"
					).find(".form-settings-tab[data-field-group='payments']");
					$.each(payment_form_settings, function () {
						$(this).addClass("disabled");
					});
					$("#ur-field-settings")
						.find("[data-field-group='payments']")
						.each(function () {
							$(this).find(":input").prop("disabled", true);
							$(this).css("opacity", 0.25);
						});

					//-> Disable payment fields from dragging when membership field is present.
					var payment_nodes = $(".ur-registered-from")
						.find(".ur-payment-fields")
						.find("li");

					$.each(payment_nodes, function (index, elem) {
						$this = $(elem);
						//➔ disable payment fields from dragging when membership field is present.
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
				}
			});
			$(document).on("ur_field_removed", function (event, data) {
				if (data.fieldKey === "membership") {
					user_registration_form_builder_data.form_has_membership_field = false;

					//➔ enable payment form settings on membership field removal.
					var payment_form_settings = $(
						"#ur-tab-field-settings"
					).find(".form-settings-tab[data-field-group='payments']");
					$.each(payment_form_settings, function () {
						$(this).removeClass("disabled");
					});

					$("#ur-field-settings")
						.find("[data-field-group='payments']")
						.each(function () {
							$(this).find(":input").prop("disabled", false);
							$(this).css("opacity", 1);
						});

					//➔ unlock payment fields on membership field removal.
					var ul_node = $("#ur-tab-registered-fields").find(
						"ul.ur-registered-list"
					);
					var payment_nodes = ul_node.find(
						"li.ur-membership-payment-field-disabled"
					);

					$.each(payment_nodes, function () {
						var $field = $(this);

						if ($field.hasClass("ur-locked-field")) {
							$field.removeClass("ur-locked-field");
						}
						$field.removeClass(
							"ur-membership-payment-field-disabled"
						);
						$field.draggable("enable");
					});
				}
			});
			$(document).on("ur_rendered_field_options", function () {
				var membership_listing_option_field = $(
						"#ur-setting-form .ur-general-setting-membership_listing_option select"
					),
					group_select_field = $(
						"#ur-setting-form .ur-general-setting-membership_group"
					),
					active_memberships_field = $(
						"#ur-setting-form .ur-general-setting-membership_active_memberships"
					);
				var val = membership_listing_option_field.val();
				group_select_field.hide();
				active_memberships_field.hide();
				if (val === "group") {
					group_select_field.show();
				} else if (val === "selected") {
					active_memberships_field.show();
				}
			});
			$(document).on(
				"user_registration_admin_before_form_submit",
				function (event, data) {
					// if (
					// 	$('[data-field="membership_listing_option"]').val() ===
					// 		"all" &&
					// 	$(".urmg-container input").length < 1
					// ) {
					// 	data.data["empty_membership_status"] = [
					// 		{
					// 			validation_status: false,
					// 			validation_message:
					// 				user_registration_form_builder_data
					// 					.i18n_admin
					// 					.i18n_prompt_no_membership_available
					// 		}
					// 	];
					// }
					// validation for empty membership group.
					// if (
					// 	$('[data-field="membership_group"]').length &&
					// 	$('[data-field="membership_group"]').val() == "0" &&
					// 	$('[data-field="membership_listing_option"]').val() ===
					// 		"group"
					// ) {
					// 	data.data["empty_membership_group_status"] = [
					// 		{
					// 			validation_status: false,
					// 			validation_message:
					// 				user_registration_form_builder_data
					// 					.i18n_admin
					// 					.i18n_prompt_no_membership_group_selected
					// 		}
					// 	];
					// }
					if (
						data.data.payment_field_present &&
						$(".ur-selected-inputs").find(
							'div[data-field-key="membership"]'
						).length
					) {
						data.data["payment_field_present_status"] = [
							{
								validation_status: false,
								validation_message:
									user_registration_form_builder_data
										.i18n_admin
										.i18n_prompt_payment_field_present
							}
						];
					}
				}
			);
			//remove membership settings on field delete
			$(document).on("ur_field_removed", function (event, data) {
				if (data.fieldKey === "membership") {
					$(".ur-general-setting-membership_listing_option").remove();
				}
			});

			$(document)
				.find("#ur-input-type-membership-group-memberships")
				.on("change", function (event) {
					var membership_ids = $(this).val();
					membership_group_object.build_upgrade_paths(
						membership_ids,
						"new"
					);
				});

			$(document)
				.find("input[name='ur_membership_enable_upgrade']")
				.on("change", function (event) {
					var membership_ids = $(
						"#ur-input-type-membership-group-memberships"
					).val();

					if ($(this).is(":checked")) {
						$(".ur-membership-upgrade-container").show();
					} else {
						$(".ur-membership-upgrade-container").hide();
					}

					membership_group_object.build_upgrade_paths(
						membership_ids,
						"mode"
					);
				});

			$(document)
				.find(".ur-sortable-list")
				.sortable({
					handle: ".ur-drag-handle",
					placeholder: "ur-sortable-placeholder",
					update: function () {
						var order = [];

						$(".ur-sortable-item").each(function () {
							order.push($(this).data("id"));
						});

						membership_group_object.build_upgrade_paths(
							order,
							"sort"
						);
					}
				});

			$(document)
				.find("input[name='ur_membership_enable_multiple_memberships']")
				.on("change", function (event) {
					if ($(this).is(":checked")) {
						$(this)
							.closest("form")
							.find(".ur-membership-enable-upgrade-container")
							.hide();
						$(this)
							.closest("form")
							.find(".ur-membership-upgrade-container")
							.hide();
					} else {
						$(this)
							.closest("form")
							.find(".ur-membership-enable-upgrade-container")
							.show();

						if (
							$(this)
								.closest("form")
								.find(
									"input[name='ur_membership_enable_upgrade']"
								)
								.is(":checked")
						) {
							$(this)
								.closest("form")
								.find(".ur-membership-upgrade-container")
								.show();
						}
					}
				});
		},
		delete_single_membership_group: function ($this) {
			var urlParams = new URLSearchParams($this.attr("href"));
			var form_title = urlParams.get("form");
			var $membership_group_id = $this.data("membership-group-id");

			if (form_title !== null) {
				Swal.fire({
					title:
						'<img src="' +
						urmg_data.delete_icon +
						'" id="delete-user-icon">' +
						urmg_data.labels.i18n_prompt_title,
					html:
						'<p id="html_1">' +
						urmg_data.labels.i18n_prompt_cannot_delete +
						"`" +
						form_title +
						"`." +
						"</p>",
					allowOutsideClick: true
				});
				return;
			}
			membership_group_object.append_spinner(parent);
			Swal.fire({
				title:
					'<img src="' +
					urmg_data.delete_icon +
					'" id="delete-user-icon">' +
					urmg_data.labels.i18n_prompt_title,
				html:
					'<p id="html_1">' +
					urmg_data.labels.i18n_prompt_single_subtitle +
					"</p>",
				showCancelButton: true,
				confirmButtonText: urmg_data.labels.i18n_prompt_delete,
				cancelButtonText: urmg_data.labels.i18n_prompt_cancel,
				allowOutsideClick: false
			}).then(function (result) {
				if (result.isConfirmed) {
					// $(location).attr("href", $this.attr("href"));
					membership_group_object.send_data(
						{
							action: "user_registration_membership_delete_membership_group",
							membership_group_id: $membership_group_id
						},
						{
							success: function (response) {
								if (response.success) {
									membership_group_object.show_success_message(
										response.data.message
									);
									membership_group_object.remove_deleted_memberships(
										$this,
										false
									);
								} else {
									membership_group_object.show_failure_message(
										response.data.message
									);
								}
							},
							failure: function (xhr, statusText) {
								membership_group_object.show_failure_message(
									urmg_data.labels.network_error +
										"(" +
										statusText +
										")"
								);
							},
							complete: function () {
								membership_group_object.remove_spinner(
									$this.closest(".delete")
								);
								// window.location.reload(); //Todo: Can be removed after fixing checkbox error and adding no content image if empty for all delete on ajax
							}
						}
					);
				}
			});
		},
		/**
		 * Handles the bulk deletion of membership groups.
		 *
		 * Displays a confirmation dialog using SweetAlert2 before proceeding
		 * with deletion. If confirmed, it collects the selected membership
		 * group IDs from the provided form and sends a request to delete them.
		 * Shows a success or failure message based on the server response.
		 *
		 * @param {jQuery} form The form element containing membership groups to delete.
		 */
		handle_bulk_delete_action: function (form) {
			Swal.fire({
				title:
					'<img src="' +
					urmg_data.delete_icon +
					'" id="delete-user-icon">' +
					urmg_data.labels.i18n_prompt_title,
				html:
					'<p id="html_1">' +
					urmg_data.labels.i18n_prompt_bulk_subtitle +
					"</p>",
				showCancelButton: true,
				confirmButtonText: urmg_data.labels.i18n_prompt_delete,
				cancelButtonText: urmg_data.labels.i18n_prompt_cancel,
				allowOutsideClick: false
			}).then(function (result) {
				if (result.isConfirmed) {
					var selected_membership_groups = form.find(
							'input[name="membership_group[]"]:checked'
						),
						membership_group_ids = [];

					if (selected_membership_groups.length < 1) {
						membership_group_object.show_failure_message(
							urmg_data.labels.i18n_prompt_no_membership_selected
						);
						return;
					}
					//prepare orders data
					selected_membership_groups.each(function () {
						if ($(this).val() !== "") {
							membership_group_ids.push($(this).val());
						}
					});

					//send request
					membership_group_object.send_data(
						{
							action: "user_registration_membership_delete_membership_groups",
							membership_group_ids:
								JSON.stringify(membership_group_ids)
						},
						{
							success: function (response) {
								if (response.success) {
									membership_group_object.show_success_message(
										response.data.message
									);
									membership_group_object.remove_deleted_memberships(
										selected_membership_groups,
										true
									);
								} else {
									membership_group_object.show_failure_message(
										response.data.message
									);
								}
							},
							failure: function (xhr, statusText) {
								membership_group_object.show_failure_message(
									urmg_data.labels.network_error +
										"(" +
										statusText +
										")"
								);
							},
							complete: function () {
								// window.location.reload(); //Todo: Can be removed after fixing checkbox error and adding no content image if empty for all delete on ajax
							}
						}
					);
				}
			});
		},
		/**
		 *
		 * @param selected_memberships
		 * @param is_multiple
		 */
		remove_deleted_memberships: function (
			selected_memberships,
			is_multiple
		) {
			if (is_multiple) {
				selected_memberships.each(function () {
					$(this).parents("tr").remove();
				});
			} else {
				$(selected_memberships).parents("tr").remove();
			}
		},
		fetch_memberships: function (group_id) {
			var loader_container = $(".urmg-loader"),
				urmg_container = $(".urmg-container"),
				empty_urmg = $(".empty-urmg-label");
			urmg_container.empty();

			if (group_id === 0) {
				empty_urmg.text(
					user_registration_form_builder_data.i18n_admin
						.i18n_empty_membership_group_text
				);
				empty_urmg.show();
				return;
			}

			// hide memberships and label
			empty_urmg.hide();
			// append spinner
			membership_group_object.append_spinner(loader_container);

			membership_group_object.send_data(
				{
					action: "user_registration_membership_get_group_memberships",
					group_id: group_id,
					list_type: group_id === -1 ? "all" : "group"
				},
				{
					success: function (response) {
						if (response.success) {
							membership_group_object.handle_membership_by_group_success_response(
								response.data,
								group_id
							);
						} else {
							empty_urmg.text(
								user_registration_form_builder_data.i18n_admin
									.i18n_prompt_no_membership_available
							);
							empty_urmg.show();
						}
					},
					failure: function (xhr, statusText) {},
					complete: function () {
						membership_group_object.remove_spinner(
							loader_container
						);
					}
				}
			);
		},
		/**
		 * Fetch memberships based on explicitly selected membership IDs.
		 *
		 * This mirrors fetch_memberships() but uses membership_active_memberships
		 * multiselect instead of a group selection.
		 */
		fetch_selected_memberships: function () {
			var loader_container = $(".urmg-loader"),
				urmg_container = $(".urmg-container"),
				empty_urmg = $(".empty-urmg-label"),
				$select = $(
					"#ur-setting-form .ur-general-setting-membership_active_memberships select"
				),
				membership_ids = $select.val() || [];

			urmg_container.empty();

			if (!membership_ids.length) {
				empty_urmg.text(
					user_registration_form_builder_data.i18n_admin
						.i18n_prompt_no_membership_available
				);
				empty_urmg.show();
				return;
			}

			// hide memberships and label
			empty_urmg.hide();
			// append spinner
			membership_group_object.append_spinner(loader_container);

			membership_group_object.send_data(
				{
					action: "user_registration_membership_get_selected_memberships",
					membership_ids: membership_ids
				},
				{
					success: function (response) {
						if (response.success) {
							membership_group_object.handle_membership_by_group_success_response(
								response.data,
								-1
							);
						} else {
							empty_urmg.text(
								user_registration_form_builder_data.i18n_admin
									.i18n_prompt_no_membership_available
							);
							empty_urmg.show();
						}
					},
					failure: function (xhr, statusText) {},
					complete: function () {
						membership_group_object.remove_spinner(
							loader_container
						);
					}
				}
			);
		},
		/**
		 * Handles the response after a successful ajax request of membership by group
		 * @param {object} data - The response data
		 * @param group_id - The response data
		 * @return {void}
		 */
		handle_membership_by_group_success_response: function (data, group_id) {
			var urmg_container = $(".urmg-container"),
				empty_urmg = $(".empty-urmg-label"),
				plans = data.plans || [],
				html = "",
				gateway_images = urmg_data.gateway_images || {},
				active_payment_gateways =
					urmg_data.active_payment_gateways || {},
				plugin_url = urmg_data.plugin_url || "",
				currency_symbol = urmg_data.currency_symbol || "$";

			if (plans.length === 0) {
				empty_urmg.text(
					user_registration_form_builder_data.i18n_admin
						.i18n_prompt_no_membership_available
				);
				empty_urmg.show();
				return;
			}

			// Hide empty label when there are memberships
			empty_urmg.hide();
			// Build membership plans HTML
			html += '<div class="urmg-membership-plans">';
			$(plans).each(function (k, item) {
				var is_first = k === 0,
					selected_class = is_first ? "selected" : "",
					plan_id = item.ID || "",
					plan_title = item.title || "",
					plan_period = item.period || "",
					plan_type = item.type || "free",
					plan_amount = item.amount || 0,
					trial_label = "",
					trial_end_display = "";

				if (
					item.trial_status === "on" &&
					item.trial_data &&
					parseInt(item.trial_data.value, 10) > 0
				) {
					var trial_value = parseInt(item.trial_data.value, 10),
						trial_duration = item.trial_data.duration || "day",
						end_date = new Date();

					trial_label =
						trial_value + "-" + trial_duration + " free trial";

					if (trial_duration === "day") {
						end_date.setDate(end_date.getDate() + trial_value);
					} else if (trial_duration === "week") {
						end_date.setDate(end_date.getDate() + trial_value * 7);
					} else if (trial_duration === "month") {
						end_date.setMonth(end_date.getMonth() + trial_value);
					} else if (trial_duration === "year") {
						end_date.setFullYear(
							end_date.getFullYear() + trial_value
						);
					}

					var month_names = [
						"Jan",
						"Feb",
						"Mar",
						"Apr",
						"May",
						"Jun",
						"Jul",
						"Aug",
						"Sep",
						"Oct",
						"Nov",
						"Dec"
					];
					trial_end_display =
						month_names[end_date.getMonth()] +
						" " +
						end_date.getDate();
				}

				html +=
					'<div class="urmg-plan-card ' +
					selected_class +
					(plans.length === 1 ? " ur-membership-single-plan" : "") +
					'" data-plan-id="' +
					plan_id +
					'" data-plan-amount="' +
					plan_amount +
					'" data-plan-type="' +
					plan_type +
					'">';
				html +=
					'<input type="radio" name="urm_membership" value="' +
					plan_id +
					'" ' +
					(is_first ? "checked" : "") +
					" disabled/>";
				html += '<div class="urmg-plan-header">';
				html += "<div>";
				html += '<div class="urmg-plan-title">' + plan_title + "</div>";
				if (trial_label) {
					html +=
						'<span class="ur-membership-trial-label">' +
						trial_label +
						"</span>";
				}
				html += "</div>";
				html += '<div class="urmg-plan-price">' + plan_period;
				if (trial_end_display) {
					var trial_ends_label =
						urmg_data &&
						urmg_data.labels &&
						urmg_data.labels.i18n_trial_ends
							? urmg_data.labels.i18n_trial_ends
							: "Trial ends";
					html +=
						'<span class="ur-membership-trial-end">' +
						trial_ends_label +
						" " +
						trial_end_display +
						"</span>";
				}
				html += "</div>";
				html += "</div>";
				html += "</div>";
			});
			html += "</div>";

			// Build total section
			var total_amount = currency_symbol + "0.00";
			html += '<div class="urmg-total-container">';
			html += '<span class="urmg-total-label">Total:</span>';
			html +=
				'<span class="urmg-total-amount" id="urmg-total-amount">' +
				total_amount +
				"</span>";
			html += "</div>";

			// Build payment gateway selection - only show if there's more than one membership, or if single membership is not free
			var show_payment_gateways = false;

			if (plans.length > 1) {
				// If more than one membership, always show payment gateways
				show_payment_gateways = true;
			} else if (plans.length === 1) {
				// If only one membership, check if it's not free
				var first_plan = plans[0];
				var plan_type = first_plan.type || "free";
				if (plan_type !== "free") {
					show_payment_gateways = true;
				}
			}

			if (
				show_payment_gateways &&
				Object.keys(active_payment_gateways).length > 0
			) {
				html += '<div class="urmg-payment-gateways">';
				html +=
					'<label class="urmg-payment-gateways-label">' +
					urmg_data.labels.i18n_select_payment_gateway +
					'<span class="required">*</span></label>';
				html += '<div class="urmg-gateway-buttons">';

				var gateway_index = 0;
				$.each(
					active_payment_gateways,
					function (gateway_key, gateway_label) {
						var is_first_gateway = gateway_index === 0,
							selected_gateway_class = is_first_gateway
								? "selected"
								: "",
							image_file = gateway_images[gateway_key] || "",
							image_url = image_file
								? plugin_url +
								  "/assets/images/settings-icons/membership-field/" +
								  image_file
								: "";

						html +=
							'<label class="urmg-gateway-btn ' +
							selected_gateway_class +
							'">';
						html +=
							'<input type="radio" name="urm_payment_method" value="' +
							gateway_key +
							'" ' +
							(is_first_gateway ? "checked" : "") +
							" disabled/>";
						if (image_url) {
							html +=
								'<img src="' +
								image_url +
								'" alt="' +
								gateway_label +
								'" class="urmg-gateway-icon" />';
						}
						html +=
							'<span class="urmg-gateway-label">' +
							gateway_label +
							"</span>";
						html += "</label>";
						gateway_index++;
					}
				);

				html += "</div>";
				html += "</div>";
			}

			urmg_container.append(html);
			$(".ur-selected-inputs .ur-general-setting-membership_group")
				.find(
					'select[data-field="membership_group"]  option[value="' +
						group_id +
						'"]'
				)
				.attr("selected", "selected");
		},
		/**
		 * Sends data to the backend API.
		 *
		 * @param {object} data The data to send.
		 * @param {object} callbacks The callbacks list.
		 *
		 * @prop {function} callbacks.success The success callback.
		 * @prop {function} callbacks.failure The failure callback.
		 * @prop {function} callbacks.beforeSend The callback before the request is sent.
		 * @prop {function} callbacks.complete The callback after the request is completed.
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
			if (!data._wpnonce && urmg_data) {
				data._wpnonce = urmg_data._nonce;
			}
			$.ajax({
				type: "post",
				dataType: "json",
				url: urmg_data.ajax_url,
				data: data,
				beforeSend: beforeSend_callback,
				success: success_callback,
				error: failure_callback,
				complete: complete_callback
			});
		},
		/**
		 * Build upgrade paths html.
		 *
		 * @param {string} membership_ids Membership Ids in the group.
		 */
		build_upgrade_paths: function (membership_ids, type) {
			var multiple_enabled = $(
					'input[name="ur_membership_enable_multiple_memberships"]'
				).is(":checked"),
				upgrade_enabled = $(
					'input[name="ur_membership_enable_upgrade"]'
				).is(":checked");
			membership_mode = multiple_enabled
				? "multiple"
				: upgrade_enabled
				? "upgrade"
				: "";

			if ("upgrade" === membership_mode) {
				var upgrade_path = $(
					'input[name="ur_membership_upgrade_path"]'
				).val();

				// Sortable UI is high → low; path builder expects low → high.
				if ("sort" === type && Array.isArray(membership_ids)) {
					membership_ids = membership_ids.slice().reverse();
				}

				var upgrade_order =
					"sort" === type
						? membership_ids
						: $('input[name="ur_membership_upgrade_order"]').val();

				var mergedUpgradeOrder = upgrade_order.slice();
				if ("new" === type) {
					var mergedUpgradeOrder = JSON.parse(mergedUpgradeOrder);
					var membershipArr = membership_ids.slice();

					for (var i = 0; i < membershipArr.length; i++) {
						membershipArr[i] = parseInt(membershipArr[i], 10);
					}
					for (var i = mergedUpgradeOrder.length - 1; i >= 0; i--) {
						if (
							membershipArr.indexOf(mergedUpgradeOrder[i]) === -1
						) {
							mergedUpgradeOrder.splice(i, 1);
						}
					}
					for (var i = 0; i < membershipArr.length; i++) {
						if (
							mergedUpgradeOrder.indexOf(membershipArr[i]) === -1
						) {
							mergedUpgradeOrder[mergedUpgradeOrder.length] =
								membershipArr[i];
						}
					}
				} else if ("mode" == type) {
					mergedUpgradeOrder = JSON.parse(mergedUpgradeOrder);

					if (mergedUpgradeOrder.length < 1) {
						mergedUpgradeOrder = membership_ids;
					}
				}

				if (mergedUpgradeOrder.length > 0) {
					if (
						"sort" !== type &&
						JSON.stringify(membership_ids) === upgrade_order
					) {
						$(document)
							.find(".ur-membership-upgrade-container")
							.show();
					} else {
						$.ajax({
							type: "post",
							dataType: "json",
							url: urmg_data.ajax_url,
							data: {
								action: "user_registration_membership_fetch_upgrade_path",
								membership_ids: mergedUpgradeOrder,
								security : urmg_data._nonce
							},
							success: function (response) {
								if (response.success) {
									var upgrade_paths =
											response.data.upgrade_paths,
										upgrade_order =
											response.data.upgrade_order,
										upgrade_paths_order =
											response.data.upgrade_paths_order;

									$(
										'input[name="ur_membership_upgrade_path"]'
									).val(JSON.stringify(upgrade_paths));
									$("#ur-membership-upgrade-order").val(
										JSON.stringify(upgrade_order)
									);

									// Keep the dragged DOM order on sort. Replacing
									// the list HTML re-applies reverse rendering and
									// snaps the UI back.
									if ("sort" !== type) {
										var $sanitized = $("<div>").html(
											upgrade_paths_order
										);
										$sanitized.find("script").remove();
										$(".ur-sortable-list").html(
											$sanitized.contents()
										);
									}
								} else {
									$(
										'input[name="ur_membership_upgrade_path"]'
									).val("");
								}

								$(document)
									.find(".ur-membership-upgrade-container")
									.show();
							}
						});
					}
				}
			} else {
				$(document).find(".ur-membership-upgrade-container").hide();
			}
		}
	};

	$(document).ready(function () {
		membership_group_object.init();
	});
})(jQuery, window.urmg_localized_data);
