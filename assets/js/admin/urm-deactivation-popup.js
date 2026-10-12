/**
 * URM deactivation popup
 *
 * Restyles ThemeGrill SDK uninstall feedback to the Quick Feedback UI.
 * Popup id is {plugin-folder}_uninstall_feedback_popup (free vs pro folders differ).
 */
(function ($) {
	"use strict";

	function initUrmPopup() {
		var cfg = window.urmDeactivationPopup || {};
		var popupId =
			cfg.popupId || "user-registration_uninstall_feedback_popup";
		var $popup = $("#" + popupId);
		if (!$popup.length || $popup.hasClass("urm-popup-initialized")) {
			return;
		}

		$popup.addClass("urm-popup-styled urm-popup-initialized");

		var $header = $popup.find(".popup--header");
		var $h5 = $header.find("h5");
		var $body = $popup.find(".popup--body");

		var logoUrl = cfg.logoUrl || "";
		var logoHtml = logoUrl
			? '<span class="urm-popup-logo"><img src="' +
			  logoUrl +
			  '" alt="User Registration"></span>'
			: '<span class="urm-popup-logo">U</span>';
		var headerInner =
			'<div class="urm-popup-header-inner">' +
			logoHtml +
			'<span class="urm-popup-title">' +
			(cfg.quickFeedback || "Quick Feedback") +
			"</span>" +
			"</div>" +
			'<button type="button" class="urm-popup-close" aria-label="Close">&times;</button>';
		$header.prepend(headerInner);

		var questionText = $h5.text().trim();
		if (questionText) {
			$body.prepend(
				'<p class="urm-popup-question">' + questionText + "</p>"
			);
		}
		$h5.remove();

		var $close = $popup.find(".urm-popup-close");
		// X must only dismiss — never follow the plugins-list Deactivate URL (#1677).
		$close.on("click", function (e) {
			e.preventDefault();
			e.stopPropagation();
			$popup.removeClass("active");
			$("body").removeClass("tgsdk-feedback-open");
		});
	}

	$(document).ready(function () {
		initUrmPopup();
	});
})(jQuery);
