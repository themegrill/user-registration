/**
 * The Connect an AI Assistant drawer on Settings, Advanced, AI Integration.
 *
 * Runs the readiness check, makes the AI user and its password in one click once the admin agrees, and shows
 * what the chosen AI app needs.
 */
(function ($) {
	"use strict";

	var cfg = window.urAiConnect || {};
	var i18n = cfg.i18n || {};
	var apps = cfg.apps || {};
	var current = "claude";

	function post(action, data) {
		return $.post(cfg.ajaxUrl, $.extend({ action: action, security: cfg.nonce }, data || {}));
	}

	function message(response) {
		return response && response.data && response.data.message ? response.data.message : i18n.failed;
	}

	function notice(text) {
		$("#ur-ai-result").text(text).prop("hidden", !text);
	}

	function copyText(text, button) {
		var done = function () {
			var label = $(button).data("label") || $(button).text();
			$(button).data("label", label).text(i18n.copied);
			setTimeout(function () {
				$(button).text(label);
			}, 1500);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () {
				fallbackCopy(text);
				done();
			});
			return;
		}

		fallbackCopy(text);
		done();
	}

	function fallbackCopy(text) {
		var area = $("<textarea>").val(text).css({ position: "fixed", opacity: 0 }).appendTo("body");
		area[0].select();
		try {
			document.execCommand("copy");
		} catch (e) {
			// The text stays on screen to copy by hand.
		}
		area.remove();
	}

	// One box per thing to paste. Before generating there is a single empty box with the waiting text; after,
	// each snippet of the app gets its own box and its own Copy button (Claude has two: Code and Desktop).
	function drawPrompts(app, data) {
		var holder = $("#ur-ai-prompts").empty();

		if (!data) {
			holder.append($('<div class="ur-ai-prompt">').append($("<pre>").attr("data-empty", holder.data("empty"))));
			return;
		}

		app.snippets.forEach(function (item) {
			var box = $('<div class="ur-ai-prompt">');

			if (item.label) {
				box.append($('<p class="ur-ai-prompt__label">').text(item.label));
			}

			if (item.hint) {
				box.append($('<p class="ur-ai-prompt__hint">').text(item.hint));
			}

			box.append($("<pre>").text(data.snippets[item.key]), $('<button type="button" class="button button-primary ur-ai-copy-prompt">').text(i18n.copy));
			holder.append(box);
		});
	}

	// A fresh tab starts from nothing: nothing generated, nothing agreed to. Each app gets its own password,
	// named after it, so the activity log can tell the apps apart.
	function selectApp(key) {
		var app = apps[key];

		if (!app) {
			return;
		}

		current = key;
		$(".ur-ai-apps__tab[data-app]").each(function () {
			$(this).toggleClass("is-active", $(this).data("app") === key);
		});
		$("#ur-ai-panel-title").text(i18n.title.replace("%s", app.label));
		$("#ur-ai-panel-intro").text(app.intro);
		$("#ur-ai-consent").prop("checked", false);
		$("#ur-ai-create").prop("disabled", true);
		drawPrompts(app, null);
		$("#ur-ai-copy-pass, #ur-ai-once").prop("hidden", true);
		$("#ur-ai-m-user, #ur-ai-m-pass").text("-");
		notice("");
	}

	// Redraw what the server says exists: the checks, the connected apps and, once the AI user exists, the
	// agreement that no longer talks about making a user.
	function applyState(data) {
		if (data.checks) {
			$("#ur-ai-checks").html(data.checks);
		}

		if (undefined !== data.connections) {
			$("#ur-ai-connections").html(data.connections);
		}

		if (data.consent) {
			var text = $("#ur-ai-consent-text");
			var manual = $("#ur-ai-manual").prop("hidden") === false;

			text.data("base", data.consent.prompt).data("alt", data.consent.password).html(manual ? data.consent.password : data.consent.prompt);
		}
	}

	function showResult(data) {
		drawPrompts(apps[current], data);
		$("#ur-ai-copy-pass, #ur-ai-once").prop("hidden", false);
		$("#ur-ai-m-user").text(data.login);
		$("#ur-ai-m-pass").text(data.password);
		$("#ur-ai-m-endpoint").text(data.endpoint);
		$("#ur-ai-copy-pass").data("password", data.password.replace(/\s+/g, ""));
	}

	// The drawer lives outside the settings form, so nothing in it is ever saved with the settings.
	$("#ur-ai-overlay, #ur-ai-drawer").appendTo("body");
	selectApp(current);

	function openDrawer() {
		$("#ur-ai-overlay").prop("hidden", false);
		$("#ur-ai-drawer").prop("hidden", false);
		// Let the browser paint the closed state first, so the slide plays.
		setTimeout(function () {
			$("#ur-ai-drawer").addClass("is-open");
			$("#ur-ai-close").trigger("focus");
		}, 20);
	}

	function closeDrawer() {
		$("#ur-ai-drawer").removeClass("is-open");
		setTimeout(function () {
			$("#ur-ai-drawer, #ur-ai-overlay").prop("hidden", true);
		}, 250);
		$("#ur-ai-open").trigger("focus");
	}

	$(document).on("click", "#ur-ai-open", openDrawer);
	$(document).on("click", "#ur-ai-close, #ur-ai-overlay", closeDrawer);
	$(document).on("keydown", function (event) {
		if ("Escape" === event.key && $("#ur-ai-drawer").hasClass("is-open")) {
			closeDrawer();
		}
	});

	$(document).on("click", ".ur-ai-apps__tab[data-app]", function () {
		selectApp($(this).data("app"));
	});

	$(document).on("change", "#ur-ai-consent", function () {
		$("#ur-ai-create").prop("disabled", !this.checked);
	});

	$(document).on("click", "#ur-ai-manual-toggle", function () {
		var button = $(this);
		var manual = $("#ur-ai-manual").prop("hidden");

		// Two views of one panel: the prompt to paste, or the details to type in by hand.
		$("#ur-ai-manual").prop("hidden", !manual);
		$("#ur-ai-prompts").prop("hidden", manual);
		button.data("label", button.data("label") || button.text());
		button.text(manual ? button.data("alt") : button.data("label"));

		// The button and the agreement say what they will do in this view.
		[$("#ur-ai-create"), $("#ur-ai-consent-text")].forEach(function (item, index) {
			var html = index ? "html" : "text";

			if (!item.data("base")) {
				item.data("base", item[html]());
			}

			item[html](manual ? item.data("alt") : item.data("base"));
		});
	});

	$(document).on("click", ".ur-ai-copy-prompt", function () {
		copyText($(this).siblings("pre").text(), this);
	});

	$(document).on("click", "#ur-ai-copy-pass", function () {
		copyText($(this).data("password"), this);
	});

	// Step 3: one question at a time, for the kind of action chosen. Another question moves on, and wraps round.
	var asks = cfg.asks || {};
	var askLevel = "read";
	var askIndex = 0;

	function showAsk() {
		var group = asks[askLevel];

		if (!group) {
			return;
		}

		$(".ur-ai-asks__tab").each(function () {
			$(this).toggleClass("is-active", $(this).data("level") === askLevel);
		});
		$("#ur-ai-ask-hint").text(group.hint);
		$("#ur-ai-ask-text").text(group.questions[askIndex]);
		$("#ur-ai-ask-count").text(askIndex + 1 + " / " + group.questions.length);
		$("#ur-ai-ask-next").prop("hidden", group.questions.length < 2);
	}

	$(document).on("click", ".ur-ai-asks__tab", function () {
		askLevel = $(this).data("level");
		askIndex = 0;
		showAsk();
	});

	$(document).on("click", "#ur-ai-ask-next", function () {
		askIndex = (askIndex + 1) % asks[askLevel].questions.length;
		showAsk();
	});

	$(document).on("click", "#ur-ai-ask-copy", function () {
		copyText($("#ur-ai-ask-text").text(), this);
	});

	// Every row spins while the site is checked, then settles one after another into a tick or a cross.
	// The spinner shows for a moment even when the answer is instant, so the check is seen to happen.
	$(document).on("click", "#ur-ai-check", function () {
		var button = $(this).prop("disabled", true);
		var list = $("#ur-ai-checks");
		var started = Date.now();

		list.children().removeClass("is-ok is-bad").addClass("is-loading");

		function settle(data) {
			var rows = $("<ul>").html(data.checks).children();

			rows.each(function (index) {
				var row = this;

				setTimeout(function () {
					list.children().eq(index).replaceWith(row);
				}, index * 220);
			});

			setTimeout(function () {
				// Rows may have been added or removed since the last check, so end on exactly what the server sent.
				list.html(data.checks);
				$("#ur-ai-last-card").text(data.last);
				button.prop("disabled", false);
			}, rows.length * 220 + 50);
		}

		post("ur_ai_connect_check")
			.done(function (response) {
				if (!response.success) {
					list.children().removeClass("is-loading");
					button.prop("disabled", false);
					return;
				}

				setTimeout(function () {
					settle(response.data);
				}, Math.max(0, 600 - (Date.now() - started)));
			})
			.fail(function () {
				list.children().removeClass("is-loading");
				button.prop("disabled", false);
			});
	});

	$(document).on("click", "#ur-ai-create", function () {
		var button = $(this).prop("disabled", true);
		var label = button.text();

		button.text(i18n.working);
		notice("");

		post("ur_ai_connect_create", { app: apps[current].name || apps[current].label })
			.done(function (response) {
				if (response.success) {
					showResult(response.data);
					applyState(response.data);
				} else {
					notice(message(response));
				}
			})
			.fail(function (xhr) {
				notice(message(xhr.responseJSON));
			})
			.always(function () {
				// The consent covers one password. Ask again for the next one.
				button.text(label).prop("disabled", true);
				$("#ur-ai-consent").prop("checked", false);
			});
	});

	$(document).on("click", "#ur-ai-revoke", function () {
		if (!window.confirm(i18n.confirm)) {
			return;
		}

		post("ur_ai_connect_revoke").done(function (response) {
			if (response.success) {
				applyState(response.data);
				selectApp(current);
				notice(i18n.removed);
			}
		});
	});

	$(document).on("click", ".ur-ai-remove-one", function () {
		if (!window.confirm(i18n.confirmOne)) {
			return;
		}

		post("ur_ai_connect_remove", { uuid: $(this).data("uuid") }).done(function (response) {
			if (response.success) {
				applyState(response.data);
				selectApp(current);
				notice(i18n.removed);
			}
		});
	});
})(jQuery);
