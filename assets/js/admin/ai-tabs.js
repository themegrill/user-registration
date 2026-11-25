/**
 * Tabs for the AI Integration settings. The cards are the ones the page already draws; this only shows one at a time.
 * The page is still one form, so Save Changes saves every tab.
 */
(function ($) {
	"use strict";

	var cfg = window.urAiTabs || {};

	$(function () {
		var box = $(".user-registration-options-container").first();
		var cards = box.children(".user-registration-card");
		var names = cfg.tabs || [];

		if (cards.length < 2 || names.length < 2) {
			box.addClass("ur-ai-tabs-ready");
			return;
		}

		// The content that used to slide in from the side (the checks, connecting an app, the questions to try) becomes
		// a tab of its own, between the two cards. It is the same markup, moved, so everything in it keeps working.
		var body = $("#ur-ai-drawer .ur-ai-drawer__body");

		if (cfg.inline && body.length) {
			var pane = $(
				'<div class="user-registration-card ur-mt-4 ur-border-0 ur-ai-connect-pane">' +
					'<div class="user-registration-card__header ur-border-0"><div class="user-registration-card__header-wrapper"><h3 class="user-registration-card__title"></h3></div></div>' +
					'<div class="pt-0 pb-0 user-registration-card__body"></div>' +
					"</div>"
			);

			pane.find(".user-registration-card__title").text(cfg.connectLabel);
			pane.find(".user-registration-card__body").append(body);
			pane.insertAfter(cards.first());
			cards = box.children(".user-registration-card");
			names = names.slice(0, 1).concat([cfg.connectLabel], names.slice(1));

			// The Connect button on the first tab takes you to this tab.
			$(document).on("ur-ai-show-connect", function () {
				show(1);
			});
		}

		var nav = $('<div class="urcr-tabs" role="tablist">');
		var active = 0;

		names.forEach(function (name, index) {
			$('<button type="button" role="tab" class="urcr-tab">').text(name).attr("data-tab", index).appendTo(nav);
		});

		nav.insertBefore(cards.first());

		// The abilities card has nothing to show while the master switch is off, so its tab waits for the switch.
		function switchOn() {
			var field = $("#" + cfg.switchId);

			return !field.length || field.is(":checked");
		}

		function paint() {
			var on = switchOn();

			cards.each(function (index) {
				$(this).toggleClass("ur-ai-pane--off", index !== active);
			});
			nav.children().each(function (index) {
				var waiting = index > 0 && !on;

				$(this).toggleClass("urcr-tab-active", index === active).toggleClass("is-disabled", waiting).attr({ "aria-selected": index === active ? "true" : "false", "aria-disabled": waiting ? "true" : "false", title: waiting ? cfg.needsOn : "" });
			});
		}

		function show(index) {
			if (index > 0 && !switchOn()) {
				return;
			}

			active = index;
			paint();

			try {
				window.history.replaceState(null, "", "#ai-tab=" + index);
			} catch (e) {
				// The tab still works without the address remembering it.
			}
		}

		nav.on("click", ".urcr-tab", function () {
			show(parseInt($(this).attr("data-tab"), 10));
		});

		$(document).on("change", "#" + cfg.switchId, function () {
			if (!switchOn()) {
				active = 0;
			}

			paint();
		});

		var wanted = /ai-tab=(\d+)/.exec(window.location.hash);

		active = wanted && parseInt(wanted[1], 10) < names.length && (parseInt(wanted[1], 10) === 0 || switchOn()) ? parseInt(wanted[1], 10) : 0;
		paint();
		box.addClass("ur-ai-tabs-ready");
	});
})(jQuery);
