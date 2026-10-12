/* global  user_registration_params */
(function ($) {
	var user_registration_form_selector;

	user_registration_form_selector = $(
		".ur-frontend-form form, form.cart, form.checkout"
	);

	var field_selector = "";

	if (user_registration_form_selector.hasClass("edit-profile")) {
		field_selector = "user_registration_";
	}

	var user_registration_form_validator = {
		$user_registration: user_registration_form_selector,
		init: function () {
			this.add_validation_methods();
			this.load_validation();
			this.init_inputMask();
			this.init_tooltipster();

			// Inline validation
			this.$user_registration.on(
				"input validate change",
				".input-text, select, input:checkbox input:radio",
				this.validate_field
			);

			$(".input-text").on("keypress", function (event) {
				$this = $(this);
				var has_max_words = Number($this.attr("max-words"));
				var words = $this.val().split(" ").length;

				if (typeof has_max_words !== "undefined") {
					if (words > has_max_words) {
						event.preventDefault();
					}
				}
			});

			// Prevent invalid key input in number fields.
			$("[type='number']").on("keypress", function (event) {
				var keyCode = event.keyCode || event.which;
				var currentValue = $(this).val();
				if (
					(keyCode !== 46 || currentValue.indexOf(".") !== -1) &&
					(keyCode < 48 || keyCode > 57)
				) {
					event.preventDefault();
				}
			});
		},
		init_inputMask: function () {
			if (typeof $.fn.inputmask !== "undefined") {
				$(".ur-masked-input").inputmask();
			}
		},
		init_tooltipster: function () {
			if (typeof tooltipster !== "undefined") {
				var tooltipster_args = {
					theme: "tooltipster-borderless",
					maxWidth: 200,
					multiple: true,
					interactive: true,
					position: "bottom",
					contentAsHTML: true,
					functionInit: function (instance, helper) {
						var $origin = jQuery(helper.origin),
							dataTip = $origin.attr("data-tip");

						if (dataTip) {
							instance.content(dataTip);
						}
					}
				};
				$(".user-registration-help-tip").tooltipster(tooltipster_args);
			}
		},
		/**
		 * Add custom validation Methods.
		 *
		 * @since 1.9.4
		 */
		add_validation_methods: function () {
			// Validate email addresses.
			$.validator.methods.email = function (value, element) {
				/* https://stackoverflow.com/questions/2855865/jquery-validate-e-mail-address-regex */
				var pattern = new RegExp(
					/^((([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+(\.([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+)*)|((\x22)((((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(([\x01-\x08\x0b\x0c\x0e-\x1f\x7f]|\x21|[\x23-\x5b]|[\x5d-\x7e]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(\\([\x01-\x09\x0b\x0c\x0d-\x7f]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))))*(((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(\x22)))@((([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.)+(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.?$/i
				);
				return this.optional(element) || pattern.test(value);
			};

			//required field
			$.validator.methods.required = function (value, element, param) {
				// Check if dependency is met
				if (!this.depend(param, element)) {
					return "dependency-mismatch";
				}
				if (element.nodeName.toLowerCase() === "select") {
					// Could be an array for select-multiple or a string, both are fine this way
					var val = $(element).val();
					return val && val.length > 0;
				}
				if (this.checkable(element)) {
					return this.getLength(value, element) > 0;
				}
				return (
					value.trim() !== undefined &&
					value.trim() !== null &&
					value.trim().length > 0
				);
			};

			/**
			 * Validation for min words.
			 *
			 * @since 3.1.2
			 */
			$.validator.addMethod(
				"wordsValidator",
				function (value, element, param) {
					var wordsCount = value.trim().split(/\s+/).length;
					if ("" == value) {
						return true;
					}
					return wordsCount >= param;
				},
				$.validator.format("Please enter at least {0} words.")
			);

			/**
			 * Validation for username length.
			 *
			 * @since 1.9.4
			 */
			$.validator.addMethod(
				"lengthValidator",
				function (value, element, param) {
					return value.length <= param;
				},
				$.validator.format("Please enter less than {0} characters.")
			);

			/**
			 * Validation for username validation for special character.
			 *
			 * @since 1.9.7
			 */
			$.validator.addMethod(
				"SpecialCharacterValidator",
				function (value, element) {
					var reg = new RegExp(
						/^(?=.{3,20}$)[a-zA-Z][a-zA-Z0-9]*(?: [a-zA-Z0-9]+)*$/
					);
					return this.optional(element) || reg.test(value);
				},
				user_registration_params.message_username_character_fields
			);

			/**
			 * Validate checkbox choice limit.
			 *
			 * @since 1.9.4
			 */
			$.validator.addMethod(
				"checkLimit",
				function (value, element, param) {
					var choiceLimit = parseInt(param || 0, 10),
						$checked = "";

					if ($(element).closest(".field-checkbox").length) {
						var ul = $(element).closest("ul");
						$checked = ul.find('input[type="checkbox"]:checked');
					} else if (
						$(element).closest(".field-multi_select2").length
					) {
						$checked = $(element).val();
					} else if (
						$(element).closest(".field-multiple_choice").length
					) {
						var ul = $(element).closest("ul");
						$checked = ul.find('input[type="checkbox"]:checked');
					}

					if (0 === choiceLimit) {
						return true;
					}

					return $checked.length <= choiceLimit;
				},

				$.validator.format(
					user_registration_params.user_registration_checkbox_validation_message
				)
			);

			$.validator.addMethod(
				"patternValidator",
				function (value, element, params) {
					var regex = new RegExp(params.pattern);
					return this.optional(element) || regex.test(value);
				},
				function (params, element) {
					return params.errorMessage;
				}
			);
		},
		load_validation: function () {
			if (typeof $.fn.validate === "undefined") {
				return false;
			}
			//Validation by pass for wc quantity field.
			var qty_max = $(document).find('[name="quantity"]');
			if (qty_max.attr("max") === "") {
				qty_max.removeAttr("max");
			}
			var $this_node = this;

			$this_node.$user_registration.each(function () {
				var $this = $(this);

				if ( !$this.parent('div').hasClass('user-registration') ) {
					return;
				}

				var validator_params = $this_node.custom_validation($this);
				$this_node.custom_validation_messages();

				$this.validate({
					errorClass: "user-registration-error",
					validClass: "user-registration-valid",
					ignore: function (index, element) {
						// Return true to ignore the element, false to include it in validation
						if (
							$(element)
								.closest(".ur-field-item")
								.is(":hidden") ||
							$(element)
								.closest(
									".ur_membership_frontend_input_container"
								)
								.is(":hidden")
						) {
							return true;
						}
						if ($(element).hasClass("ur-flatpickr-field")) {
							return true;
						}

						// return (
						// 	element.id &&
						// 	(element.id.startsWith("billing_") ||
						// 		element.id.startsWith("shipping_") ||
						// 		element.id.startsWith("quantity_"))
						// );

						if (
							element.id &&
							element.id.startsWith("shipping_") &&
							$(element).closest(".form-row").is(":hidden")
						) {
							return true;
						}
					},
					rules: validator_params.rules,
					messages: validator_params.messages,
					focusInvalid: false,
					invalidHandler: function (form, validator) {
						if (!validator.numberOfInvalids()) return;

						// Scroll to first error message on submit.
						$(window).scrollTop(
							$(validator.errorList[0].element).offset().top
						);
					},
					errorPlacement: function (error, element) {
						if (
							element.is("#password_current") ||
							element.is("#password_1") ||
							element.is("#password_2")
						) {
							element.parent().after(error);
						} else if (
							"radio" === element.attr("type") ||
							"checkbox" === element.attr("type") ||
							"password" === element.attr("type")
						) {
							element.parent().parent().parent().append(error);
						} else if (
							element.is("select") &&
							element
								.attr("class")
								.match(/date-month|date-day|date-year/)
						) {
							if (
								element
									.parent()
									.find(
										"label.user-registration-error:visible"
									).length === 0
							) {
								element
									.parent()
									.find("select:last")
									.after(error);
							}
						} else if (element.hasClass("ur-smart-phone-field")) {
							var wrapper = element.closest(".form-row");
							wrapper
								.find("#" + element.data("id") + "-error")
								.remove();
							wrapper.append(error);
						} else if (
							"number" === element.attr("type") &&
							element.hasClass("ur-range-input")
						) {
							error.insertAfter(
								element
									.closest(".ur-range-row")
									.find(".ur-range-number")
							);
						} else if (
							"text" === element.attr("type") &&
							element.hasClass("input-timepicker")
						) {
							if (!element.hasClass("timepicker-end")) {
								error.insertAfter(element.parent());
							}
						} else {
							$(document).trigger(
								"user-registration-append-error-messages",
								element
							);
							if (
								element.hasClass("urfu-file-input") ||
								element.closest(".field-multi_select2").length
							) {
								error.insertAfter(element.parent().parent());
							} else if (
								"number" === element.attr("type") &&
								element.hasClass("ur-quantity")
							) {
								error.insertAfter(element.parent());
							} else if (
								"text" === element.attr("type") &&
								element.hasClass("ur-payment-price")
							) {
								error.insertAfter(element);
							} else if ("url" === element.attr("type")) {
								error.insertAfter(element.parent());
							} else {
								error.insertAfter(element.parent().parent());
							}
						}
					},
					highlight: function (element, errorClass, validClass) {
						var $element = $(element),
							$parent = $element.closest(".form-row"),
							inputName = $element.attr("name");
						$element
							.removeClass("ur-input-border-green")
							.addClass("ur-input-border-red");
					},
					unhighlight: function (element, errorClass, validClass) {
						var $element = $(element),
							$parent = $element.closest(".form-row"),
							inputName = $element.attr("name");
						$element
							.removeClass("ur-input-border-red")
							.addClass("ur-input-border-green");

						if (
							$element.attr("type") === "radio" ||
							$element.attr("type") === "checkbox"
						) {
							$parent
								.find("input[name='" + inputName + "']")
								.addClass(validClass)
								.removeClass(errorClass);
						} else {
							$element
								.addClass(validClass)
								.removeClass(errorClass);
						}

						$parent.removeClass("user-registration-has-error");
					},
					submitHandler: function (form) {
						/**
						 * Return `false` for `Registration` form and `Edit Profile` when ajax submission is on to allow ajax submission
						 */
						if (
							$(form).hasClass("register") ||
							($(form).hasClass("edit-profile") &&
								user_registration_params.ajax_submission_on_edit_profile)
						) {
							return false;
						}

						return true;
					}
				});
			});
		},
		validate_field: function (e) {
			// Validator messages.
			$.extend($.validator.messages, {
				required: user_registration_params.message_required_fields,
				url: user_registration_params.message_url_fields,
				email: user_registration_params.message_email_fields,
				number: user_registration_params.message_number_fields,
				confirmpassword:
					user_registration_params.message_confirm_password_fields
			});

			var $this = $(this),
				$parent = $this.closest(".form-row"),
				validated = true,
				validate_required = $parent.is(".validate-required"),
				validate_email = $parent.is(".validate-email"),
				event_type = e.type;

			if ("input" === event_type) {
				$parent.removeClass(
					"user-registration-invalid user-registration-invalid-required-field user-registration-invalid-email user-registration-validated"
				);
			}

			if ("validate" === event_type || "change" === event_type) {
				if (validate_required) {
					if (
						"checkbox" === $this.attr("type") &&
						!$this.is(":checked")
					) {
						$parent
							.removeClass("user-registration-validated")
							.addClass(
								"user-registration-invalid user-registration-invalid-required-field"
							);
						validated = false;
					} else if ($this.val() === "") {
						$parent
							.removeClass("user-registration-validated")
							.addClass(
								"user-registration-invalid user-registration-invalid-required-field"
							);
						validated = false;
					}
				}

				if (validate_email) {
					if ($this.val()) {
						/* https://stackoverflow.com/questions/2855865/jquery-validate-e-mail-address-regex */
						var pattern = new RegExp(
							/^((([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+(\.([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+)*)|((\x22)((((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(([\x01-\x08\x0b\x0c\x0e-\x1f\x7f]|\x21|[\x23-\x5b]|[\x5d-\x7e]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(\\([\x01-\x09\x0b\x0c\x0d-\x7f]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))))*(((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(\x22)))@((([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.)+(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.?$/i
						);

						if (!pattern.test($this.val())) {
							$parent
								.removeClass("user-registration-validated")
								.addClass(
									"user-registration-invalid user-registration-invalid-email"
								);
							validated = false;
						}
					}
				}

				if (validated) {
					$parent
						.removeClass(
							"user-registration-invalid user-registration-invalid-required-field user-registration-invalid-email"
						)
						.addClass("user-registration-validated");
				}
			}
		},
		/**
		 * Add custom validation messages.
		 */
		custom_validation: function (this_node) {
			var rules = {},
				messages = {};

			var minWordsDiv = this_node.find("[data-min-words]");
			if (minWordsDiv.length) {
				/**
				 * For real time min words validation
				 */
				$.each(minWordsDiv, function (key, element) {
					var minWordsValidator = {};
					$this = $(element);

					minWordsValidator.wordsValidator = $this.data("min-words");

					var selector = $this.data("id");
					rules[selector] = minWordsValidator;

					messages[selector] = {
						wordsValidator:
							user_registration_params.message_min_words_fields.replace(
								"%qty%",
								minWordsValidator.wordsValidator
							)
					};
				});
			}

			if (this_node.find("#user_confirm_email").length) {
				/**
				 * For real time email matching
				 */
				var form_id = this_node.closest(".ur-frontend-form").attr("id");

				rules.user_confirm_email = {
					required: true,
					equalTo: "#" + form_id + " #user_email"
				};
				messages.user_confirm_email = {
					required: user_registration_params.message_required_fields,
					equalTo:
						user_registration_params.message_confirm_email_fields
				};
			}

			if (
				this_node.hasClass("edit-password") ||
				this_node.hasClass("ur_lost_reset_password")
			) {
				/**
				 * Password matching for `Change Password` form
				 */
				rules.password_2 = {
					equalTo: "#password_1"
				};
				messages.password_2 =
					user_registration_params.message_confirm_password_fields;
			} else if (
				this_node.hasClass("register") &&
				this_node.find("#user_confirm_password").length
			) {
				/**
				 * Password matching for registration form
				 */
				var form_id = this_node.closest(".ur-frontend-form").attr("id");

				rules.user_confirm_password = {
					required: true,
					equalTo: "#" + form_id + " #user_pass"
				};
				messages.user_confirm_password = {
					required: user_registration_params.message_required_fields,
					equalTo:
						user_registration_params.message_confirm_password_fields
				};
			}

			/**
			 * Real time username length validation and special character validation in username
			 */
			var user_login_div = this_node.find("#user_login");
			var username_validator = {};
			if (
				user_login_div.length &&
				"undefined" !== typeof user_login_div.data("username-length")
			) {
				username_validator.lengthValidator =
					user_login_div.data("username-length");
			}

			if (
				typeof user_login_div.data("username-character") ===
					"undefined" &&
				this_node.closest(".ur-frontend-form").find(".register").length
			) {
				username_validator.SpecialCharacterValidator = true;
			}

			rules.user_login = username_validator;

			/**
			 * Real time choice limit validation
			 */
			var checkbox_div = this_node.find(".field-checkbox"),
				multiselect2_div = this_node.find(".field-multi_select2"),
				multiple_choice_div = this_node.find(".field-multiple_choice");

			if (checkbox_div.length) {
				checkbox_div.each(function () {
					if (
						$(this)
							.attr("data-field-id")
							.indexOf("user_registration_") > -1
					) {
						field_selector = "";
					}
					rules[
						field_selector + $(this).attr("data-field-id") + "[]"
					] = {
						checkLimit: $(this).find("ul").data("choice-limit")
							? $(this).find("ul").data("choice-limit")
							: 0
					};
				});
			}

			if (multiselect2_div.length) {
				multiselect2_div.each(function () {
					if (
						$(this)
							.attr("data-field-id")
							.indexOf("user_registration_") > -1
					) {
						field_selector = "";
					}

					rules[
						field_selector + $(this).attr("data-field-id") + "[]"
					] = {
						checkLimit: $(this).find("select").data("choice-limit")
							? $(this).find("select").data("choice-limit")
							: 0
					};
				});
			}

			if (multiple_choice_div.length) {
				multiple_choice_div.each(function () {
					rules[field_selector + $(this).data("field-id") + "[]"] = {
						checkLimit: $(this).find("ul").data("choice-limit")
							? $(this).find("ul").data("choice-limit")
							: 0
					};
				});
			}

			$('div[data-field-pattern-enabled="1"]').each(function () {
				var $div = $(this);
				var inputId = $div.data("field-id");
				var pattern = $div.data("field-pattern-value");
				var errorMessage = $div.data("field-pattern-message");

				rules[inputId] = {
					patternValidator: {
						pattern: pattern,
						errorMessage: errorMessage,
						param: {
							pattern: pattern,
							errorMessage: errorMessage
						}
					}
				};
			});

			return { rules: rules, messages: messages };
		},
		/**
		 * Override default validaton messages and add custom validation messsages.
		 */
		custom_validation_messages: function () {
			// Override default jquery validator messages with our plugin's validation messages.
			$.validator.messages.required =
				user_registration_params.message_required_fields;
			$.validator.messages.url =
				user_registration_params.message_url_fields;
			$.validator.messages.email =
				user_registration_params.message_email_fields;
			$.validator.messages.number =
				user_registration_params.message_number_fields;
			$.validator.messages.confirmpassword =
				user_registration_params.message_confirm_password_fields;
			$.validator.messages.max = function (params, element) {
				return user_registration_params.message_confirm_number_field_max.replace(
					"%qty%",
					element.max
				);
			};
			$.validator.messages.min = function (params, element) {
				return user_registration_params.message_confirm_number_field_min.replace(
					"%qty%",
					element.min
				);
			};
			$.validator.messages.step = function (params, element) {
				return user_registration_params.message_confirm_number_field_step.replace(
					"%qty%",
					element.step
				);
			};

			$.validator.messages.minlength = function (params, element) {
				return user_registration_params.message_min_length_fields.replace(
					"%qty%",
					params
				);
			};

			$.validator.messages.maxlength = function (params, element) {
				return user_registration_params.message_max_length_fields.replace(
					"%qty%",
					params
				);
			};
		}
	};

	$(window).on("load", function () {
		user_registration_form_validator.init();
	});

	$(window).on("user_registration_repeater_modified", function () {
		user_registration_form_validator.init();
	});
})(jQuery);
