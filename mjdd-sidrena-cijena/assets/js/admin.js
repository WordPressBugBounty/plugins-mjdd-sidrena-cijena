(function ($) {
	"use strict";

	function syncCjm($toggle) {
		var $wrap = $toggle.closest(".sidrena-cijena-cjm, .sidrena-cijena-variation-fields, .sidrena-cijena-admin-fields");
		var $suffix = $wrap.find(".sidrena-cijena-cjm-suffix");
		if ($toggle.is(":checked")) {
			$suffix.removeAttr("hidden").show();
		} else {
			$suffix.attr("hidden", "hidden").hide();
		}
	}

	function stripDateFromPrefix(prefix) {
		return String(prefix || "")
			.replace(/\s*\(\s*\d{1,2}\.\d{1,2}\.\d{4}\.?\s*\)\s*/g, "")
			.replace(/\s*:\s*\d{1,2}\.\d{1,2}\.\d{4}\.?\s*/g, "")
			.replace(/\s+\d{1,2}\.\d{1,2}\.\d{4}\.?\s*/g, " ")
			.replace(/\s+/g, " ")
			.trim();
	}

	function normalizePreviewDate(date) {
		date = $.trim(String(date || ""));
		if (!date) {
			return "";
		}
		return date.replace(/\.+$/, "") + ".";
	}

	function bindPrefixPreview() {
		var $code = $("#sidrena-cijena-preview");
		if (!$code.length) {
			return;
		}

		function updatePreview() {
			var prefix = stripDateFromPrefix($("#sidrena_cijena_prefix").val());
			var date = normalizePreviewDate($("#sidrena_cijena_default_date").val());
			var style = $('input[name="sidrena_cijena_date_style"]:checked').val();

			if (!prefix && style !== "plain") {
				prefix = (window.sidrenaCijenaAdmin && sidrenaCijenaAdmin.defaultPrefix) || "Bazna cijena";
			}

			var label = prefix;
			if (date) {
				if (!prefix) {
					label = date;
				} else {
					label = style === "plain" ? prefix + " " + date : prefix + " (" + date + ")";
				}
			}

			$code.text(label + (prefix ? ": " : " ") + "19,99 €");
		}

		$(document).on(
			"input change",
			"#sidrena_cijena_prefix, #sidrena_cijena_default_date, input[name='sidrena_cijena_date_style']",
			updatePreview
		);

		updatePreview();
	}

	function bindTooltipToggle() {
		var $toggle = $("#sidrena_cijena_enable_tooltip");
		if (!$toggle.length) {
			return;
		}

		function sync() {
			$(".sidrena-cijena-tooltip-fields").prop("hidden", !$toggle.is(":checked"));
		}

		$toggle.on("change", sync);
		sync();
	}

	function bindCjm() {
		$(".sidrena-cijena-cjm-toggle").each(function () {
			syncCjm($(this));
		});
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}

		var deferred = $.Deferred();
		var $ta = $("<textarea>")
			.css({ position: "fixed", left: "-9999px", top: "0" })
			.val(text)
			.appendTo("body");
		$ta[0].select();
		try {
			document.execCommand("copy");
			deferred.resolve();
		} catch (e) {
			deferred.reject(e);
		}
		$ta.remove();
		return deferred.promise();
	}


	function formatProgress(seen, total) {
		var tpl = sidrenaCijenaAdmin.i18n.progress || "Obrada… %1$s / %2$s";
		return tpl.replace("%1$s", String(seen)).replace("%2$s", String(total));
	}

	function bindSnapshot() {
		var $buttons = $(".sidrena-cijena-snapshot-btn");
		var $status = $(".sidrena-cijena-snapshot-status");
		var $detail = $("#sidrena-snapshot-detail");
		var $progress = $("#sidrena-snapshot-progress");
		var $bar = $("#sidrena-snapshot-bar");
		var resume = null;

		if (!$buttons.length || typeof sidrenaCijenaAdmin === "undefined") {
			return;
		}

		function clearDetail() {
			if (!$detail.length) {
				return;
			}
			$detail.attr("hidden", "hidden").empty().removeClass("is-error");
		}

		function renderDiagnostics(diagnostics) {
			if (!$detail.length || !diagnostics) {
				return;
			}

			var messages = diagnostics.messages || [];
			var catalog = diagnostics.catalog || {};
			var status = diagnostics.status || "ok";
			var parts = [];

			if (diagnostics.catalog_line) {
				parts.push(
					$("<p>", {
						class: "sidrena-cijena-snapshot-detail__stats",
						text: diagnostics.catalog_line,
					})
				);
			} else if (sidrenaCijenaAdmin.i18n.catalogStats) {
				var statsLine = sidrenaCijenaAdmin.i18n.catalogStats
					.replace("%1$d", String(catalog.eligible || 0))
					.replace("%2$d", String(catalog.filled || 0))
					.replace("%3$d", String(catalog.missing || 0))
					.replace("%4$d", String(diagnostics.parents_total || 0));
				parts.push(
					$("<p>", {
						class: "sidrena-cijena-snapshot-detail__stats",
						text: statsLine,
					})
				);
			}

			if (messages.length) {
				var $list = $("<ul>");
				messages.forEach(function (line) {
					$list.append($("<li>").text(line));
				});
				parts.push($list);
			}

			if (!parts.length) {
				clearDetail();
				return;
			}

			$detail.removeAttr("hidden").empty();
			if (status === "error") {
				$detail.addClass("is-error");
			}
			parts.forEach(function ($node) {
				$detail.append($node);
			});
		}

		function finishSnapshot(stampToday, writtenRun, diagnostics) {
			var doneCountTpl = stampToday
				? sidrenaCijenaAdmin.i18n.doneCountToday
				: sidrenaCijenaAdmin.i18n.doneCount;
			var warn = diagnostics && diagnostics.status && diagnostics.status !== "ok";
			var text;

			$status.removeClass("is-warning is-error");

			if (writtenRun > 0) {
				text = doneCountTpl
					? doneCountTpl.replace("%d", String(writtenRun))
					: stampToday
						? sidrenaCijenaAdmin.i18n.doneToday
						: sidrenaCijenaAdmin.i18n.done;
			} else if (warn) {
				text = sidrenaCijenaAdmin.i18n.fillNothing || sidrenaCijenaAdmin.i18n.error;
				$status.addClass(diagnostics.status === "error" ? "is-error" : "is-warning");
			} else {
				text = stampToday ? sidrenaCijenaAdmin.i18n.doneToday : sidrenaCijenaAdmin.i18n.done;
			}

			$status.text(text);
			if (warn || (diagnostics && diagnostics.messages && diagnostics.messages.length)) {
				renderDiagnostics(diagnostics);
			} else {
				clearDetail();
			}
		}

		function setProgress(seen, total, done) {
			var percent = 0;
			if (done) {
				percent = 100;
			} else if (total > 0) {
				percent = Math.max(0, Math.min(100, Math.round((seen / total) * 100)));
			}
			$progress.removeAttr("hidden");
			$bar.css("width", percent + "%");
		}

		$buttons.on("click", function () {
			var stampToday = this.id === "sidrena-cijena-run-snapshot-today";
			var page = 1;
			var offset = 0;
			var child = 0;
			var writtenRun = 0;
			var attempts = 0;

			if (stampToday && !window.confirm(sidrenaCijenaAdmin.i18n.todayConfirm)) {
				return;
			}

			if (resume && resume.stampToday === stampToday) {
				page = resume.page;
				offset = resume.offset;
				child = resume.child;
				writtenRun = resume.writtenRun || 0;
			}

			$buttons.prop("disabled", true);
			$status.removeClass("is-warning is-error").text(sidrenaCijenaAdmin.i18n.running);
			clearDetail();
			setProgress(0, 0, false);

			function remember() {
				resume = {
					stampToday: stampToday,
					page: page,
					offset: offset,
					child: child,
					writtenRun: writtenRun,
				};
			}

			function failWithMessage(message) {
				resume = null;
				$status.removeClass("is-warning").addClass("is-error");
				$status.text(message || sidrenaCijenaAdmin.i18n.error);
				$buttons.prop("disabled", false);
			}

			function runBatch() {
				$.ajax({
					url: sidrenaCijenaAdmin.ajaxUrl,
					method: "POST",
					timeout: 90000,
					data: {
						action: "sidrena_cijena_snapshot",
						nonce: sidrenaCijenaAdmin.nonce,
						page: page,
						offset: offset,
						child: child,
						written_run: writtenRun,
						stamp_today: stampToday ? "1" : "0",
					},
				})
					.done(function (response) {
						if (!response || !response.success) {
							var message =
								response && response.data && response.data.message
									? response.data.message
									: "";
							if (message) {
								failWithMessage(message);
								return;
							}
							failBatch();
							return;
						}

						if (!response.data) {
							failBatch();
							return;
						}

						attempts = 0;
						var data = response.data;
						var seen = parseInt(data.seen, 10) || 0;
						var total = parseInt(data.total, 10) || 0;
						writtenRun = parseInt(data.written_run, 10) || 0;

						if (data.done) {
							resume = null;
							setProgress(total || seen, total || seen, true);
							finishSnapshot(stampToday, writtenRun, data.diagnostics);
							$buttons.prop("disabled", false);
							return;
						}

						page = parseInt(data.next_page, 10) || page;
						offset = parseInt(data.next_offset, 10) || 0;
						child = parseInt(data.next_child, 10) || 0;
						remember();
						setProgress(seen, total, false);
						$status.text(formatProgress(seen, total));
						runBatch();
					})
					.fail(function (jqXHR) {
						var response = jqXHR.responseJSON;
						var message =
							response && response.data && response.data.message
								? response.data.message
								: "";
						if (message && attempts >= 4) {
							failWithMessage(message);
							return;
						}
						failBatch();
					});
			}

			function failBatch() {
				attempts += 1;
				remember();
				if (attempts < 5) {
					$status.text(sidrenaCijenaAdmin.i18n.retry || sidrenaCijenaAdmin.i18n.running);
					window.setTimeout(runBatch, 1200 * attempts);
					return;
				}
				$status.removeClass("is-warning").addClass("is-error");
				$status.text(sidrenaCijenaAdmin.i18n.stalled || sidrenaCijenaAdmin.i18n.error);
				$buttons.prop("disabled", false);
			}

			runBatch();
		});
	}


	function bindPurge() {
		var $button = $("#sidrena-cijena-purge");
		var $status = $(".sidrena-cijena-purge-status");

		if (!$button.length || typeof sidrenaCijenaAdmin === "undefined") {
			return;
		}

		$button.on("click", function () {
			if (!window.confirm(sidrenaCijenaAdmin.i18n.purgeConfirm)) {
				return;
			}

			$button.prop("disabled", true);
			$status.text(sidrenaCijenaAdmin.i18n.purging);

			$.post(sidrenaCijenaAdmin.ajaxUrl, {
				action: "sidrena_cijena_purge",
				nonce: sidrenaCijenaAdmin.purgeNonce,
			})
				.done(function (response) {
					if (!response || !response.success || !response.data || !response.data.redirect) {
						$status.text(sidrenaCijenaAdmin.i18n.error);
						$button.prop("disabled", false);
						return;
					}

					window.location.href = response.data.redirect;
				})
				.fail(function () {
					$status.text(sidrenaCijenaAdmin.i18n.error);
					$button.prop("disabled", false);
				});
		});
	}


	function bindArchiveAdmin() {
		var dialog = document.getElementById("sidrena-archive-delete-dialog");
		if (!dialog || typeof sidrenaCijenaAdmin === "undefined") {
			return;
		}

		var pendingName = "";
		var pendingAll = false;
		var $pending = $();
		var singlePrompt = $(dialog).find("p").first().text();

		$(document).on("click", ".sidrena-archive-tabs__item", function () {
			var id = $(this).attr("data-archive-tab");
			$(".sidrena-archive-tabs__item").removeClass("is-active").attr("aria-selected", "false");
			$(this).addClass("is-active").attr("aria-selected", "true");
			$(".sidrena-archive-panel").attr("hidden", "hidden").removeClass("is-active");
			$(".sidrena-archive-panel")
				.filter(function () {
					return $(this).attr("data-archive-panel") === id;
				})
				.removeAttr("hidden")
				.addClass("is-active");
		});

		$(document).on("click", ".sidrena-archive-delete", function () {
			pendingAll = false;
			pendingName = $(this).attr("data-name") || "";
			$pending = $(this).closest("li");
			$(dialog).find("p").first().text(singlePrompt);
			$(dialog).find(".sidrena-archive-dialog__name").text(pendingName);
			if (typeof dialog.showModal === "function") {
				dialog.showModal();
			} else {
				dialog.setAttribute("open", "open");
			}
		});

		$(document).on("click", ".sidrena-archive-delete-all", function () {
			pendingAll = true;
			pendingName = "";
			$pending = $();
			$(dialog)
				.find("p")
				.first()
				.text(sidrenaCijenaAdmin.i18n.archiveDeleteAllConfirm || singlePrompt);
			$(dialog).find(".sidrena-archive-dialog__name").text("");
			if (typeof dialog.showModal === "function") {
				dialog.showModal();
			} else {
				dialog.setAttribute("open", "open");
			}
		});

		$(dialog).on("click", ".sidrena-archive-delete-cancel", function () {
			if (typeof dialog.close === "function") {
				dialog.close();
			} else {
				dialog.removeAttribute("open");
			}
		});

		$(dialog).on("click", ".sidrena-archive-delete-confirm", function () {
			var $button = $(this);
			if (!pendingAll && !pendingName) {
				return;
			}
			$button.prop("disabled", true);
			$.post(sidrenaCijenaAdmin.ajaxUrl, pendingAll
				? {
					action: "sidrena_cijena_archive_delete_all",
					nonce: sidrenaCijenaAdmin.archiveNonce,
				}
				: {
					action: "sidrena_cijena_archive_delete",
					nonce: sidrenaCijenaAdmin.archiveNonce,
					name: pendingName,
				})
				.done(function (response) {
					if (!response || !response.success) {
						window.alert(
							(response && response.data && response.data.message) ||
								sidrenaCijenaAdmin.i18n.archiveDeleteFail ||
								sidrenaCijenaAdmin.i18n.error
						);
						return;
					}
					if (pendingAll) {
						$(".sidrena-archive-list").replaceWith(
							$("<p>", {
								class: "description sidrena-archive-empty",
								text: sidrenaCijenaAdmin.i18n.archiveEmpty || "",
							})
						);
						$(".sidrena-archive-delete-all").remove();
						pendingAll = false;
					} else {
						var $panel = $pending.closest(".sidrena-archive-panel");
						$pending.remove();
						if (!$panel.find("li").length) {
							$panel.find(".sidrena-archive-list").replaceWith(
								$("<p>", {
									class: "description sidrena-archive-empty",
									text: sidrenaCijenaAdmin.i18n.archiveEmpty || "",
								})
							);
						}
						if (!$(".sidrena-archive-list li").length) {
							$(".sidrena-archive-delete-all").remove();
						}
					}
					if (typeof dialog.close === "function") {
						dialog.close();
					} else {
						dialog.removeAttribute("open");
					}
				})
				.fail(function () {
					window.alert(sidrenaCijenaAdmin.i18n.archiveDeleteFail || sidrenaCijenaAdmin.i18n.error);
				})
				.always(function () {
					$button.prop("disabled", false);
				});
		});
	}

	$(function () {
		bindPrefixPreview();
		bindTooltipToggle();
		bindCjm();
		bindArchiveAdmin();

		$(document).on("change", ".sidrena-cijena-cjm-toggle", function () {
			syncCjm($(this));
		});

		$("#woocommerce-product-data").on(
			"woocommerce_variations_loaded woocommerce_variations_added",
			bindCjm
		);

		$(document).on("click", ".sidrena-cijena-copy", function () {
			var $btn = $(this);
			var text = $btn.attr("data-copy") || $btn.data("copy") || "";
			var html = $btn.html();
			var done =
				typeof sidrenaCijenaAdmin !== "undefined" && sidrenaCijenaAdmin.i18n
					? sidrenaCijenaAdmin.i18n.copied
					: "Kopirano";

			if (!text) {
				return;
			}

			copyText(String(text)).then(function () {
				$btn.text(done);
				window.setTimeout(function () {
					$btn.html(html);
				}, 1600);
			});
		});

		bindSnapshot();
		bindPurge();
	});
})(jQuery);
