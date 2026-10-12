jQuery(function ($) {
	var login_nonce = ur_login_params.ur_login_form_save_nonce;
	var update_login_nonce = function() {
		$.ajax({
			url: user_registration_params.ajax_url,
			data: {
				action: 'user_registration_get_recent_nonce',
				nonce_for: 'login'
			},
			type: "POST",
			async: true,
			complete: function (ajax_response) {
				var response = JSON.parse(ajax_response.responseText);
				if(response.success) {
					login_nonce = response.data;
				}
			}
		});
	};
	update_login_nonce();
	$(".ur-frontend-form")
		.find("form.login")
		.each(function () {
			var $ur_login_ajax_form = $(this);

			$ur_login_ajax_form
				.find("#user_registration_ajax_login_submit")
				.on("click", function (e) {
					e.preventDefault();
					var $this = $(this);
					var login_form = $this.closest("form")[0];

					if (login_form && !login_form.checkValidity()) {
						login_form.reportValidity();
						return;
					}

					var username = $this
						.closest("form")
						.find('input[name="username"]')
						.val();
					var password = $this
						.closest("form")
						.find('input[name="password"]')
						.val();
					var rememberme = $this
							.closest("form")
							.find('input[name="rememberme"]')
							.val(),
						redirect_url = $this
							.closest("form")
							.find('input[name="redirect"]')
							.val();
					if ("hCaptcha" === ur_login_params.recaptcha_type) {
						var CaptchaResponse = $this
							.closest("form")
							.find('[name="h-captcha-response"]')
							.val();
					} else if (
						"cloudflare" === ur_login_params.recaptcha_type
					) {
						var CaptchaResponse = $this
							.closest("form")
							.find('[name="cf-turnstile-response"]')
							.val();
					} else {
						var CaptchaResponse = $this
							.closest("form")
							.find('[name="g-recaptcha-response"]')
							.val();
					}

					var url =
						ur_login_params.ajax_url +
						"?action=user_registration_ajax_login_submit&security=" +
						login_nonce;

					if (window.location.href.indexOf("pl=true") > -1) {
						// "pl=true" is present in the URL.
						url += "&pl=true";
					}

					$this
						.closest("form")
						.find(".ur-submit-button span")
						.addClass("ur-spinner");

					var data = {
						username: username,
						password: password,
						CaptchaResponse: CaptchaResponse,
						redirect: redirect_url,
						previous_page: document.referrer ? document.referrer : ''
					};

					if (
						$this
							.closest("form")
							.find('input[name="rememberme"]')
							.is(":checked")
					) {
						data.rememberme = rememberme;
					}

					$.ajax({
						type: "POST",
						url: url,
						data: data,
						success: function (res) {
							$this
								.closest("form")
								.find(".ur-submit-button span")
								.removeClass("ur-spinner");

							// custom error message
							if (res.success == false) {
								$(document).trigger(
									"user_registration_after_login_failed",
									[$this]
								);

								$this
									.closest("#user-registration")
									.find(".user-registration-error")
									.remove();

								$this
									.closest("")
									.find(".user-registration-message")
									.remove();

								var $errorUl = $('<ul class="user-registration-error"></ul>');
								$errorUl.html(res.data.message);
								$this
									.closest(".ur-frontend-form")
									.prepend($errorUl);
							} else {
								if (res.data.status) {
									$this
										.closest("#user-registration")
										.find(".user-registration-error")
										.remove();

									$this
										.closest("#user-registration")
										.find(".user-registration-message")
										.remove();

									var $msgUl = $('<ul class="user-registration-message"></ul>');
								$msgUl.html(res.data.message);
								$this
									.closest(".ur-frontend-form")
									.prepend($msgUl);

									$this
										.closest("#user-registration")
										.find("input#username")
										.val("");
								} else {
									window.location.href = res.data.message;
								}
							}
						}
					}).fail(function () {
						$this
							.closest("#user-registration")
							.find(".user-registration-error")
							.remove();
						$this
							.closest("form")
							.find(".ur-submit-button span")
							.removeClass("ur-spinner");

						// Add a hidden input to ensure $_POST['login'] is present
						var loginValue = 'Login',
							$hiddenLogin = $('<input>', {
								type: 'hidden',
								name: 'login',
								value: loginValue
							}),
							$resubmitted = $('<input>', {
								type: 'hidden',
								name: 'resubmitted',
								value: true
							});
						$ur_login_ajax_form.append($hiddenLogin);
						$ur_login_ajax_form.append($resubmitted);
						$ur_login_ajax_form[0].submit();
					});
				});
		});

});
