/**
 * Widget "Calcule o frete para o seu CEP" na página do produto.
 * Chama a REST vavi/v1/simulate-freight e renderiza as opções.
 */
(function ($) {
	'use strict';

	$(function () {
		$('.vavi-freight').each(function () {
			var $widget = $(this);
			var productId = $widget.data('product-id');
			var $cep = $widget.find('.vavi-freight__cep');
			var $btn = $widget.find('.vavi-freight__btn');
			var $result = $widget.find('.vavi-freight__result');

			$btn.on('click', function () {
				var zip = $cep.val().replace(/\D/g, '');
				if (zip.length !== 8) {
					$result
						.removeClass('vavi-freight__result--error')
						.html('<p class="vavi-freight__msg vavi-freight__msg--error">' + VAVI_WC.invalid_cep + '</p>');
					return;
				}

				$btn.prop('disabled', true).text(VAVI_WC.loading);

				$.post(VAVI_WC.rest_url, {
					zip_code: zip,
					product_id: productId,
					nonce: VAVI_WC.nonce
				})
					.done(function (res) {
						$btn.prop('disabled', false).text(VAVI_WC.calculate);

						if (!res || !res.success) {
							$result.html('<p class="vavi-freight__msg vavi-freight__msg--error">' + (res && res.message ? res.message : VAVI_WC.error) + '</p>');
							return;
						}

						if (!res.options || !res.options.length) {
							$result.html('<p class="vavi-freight__msg vavi-freight__msg--error">' + VAVI_WC.no_options + '</p>');
							return;
						}

						var html = '<ul class="vavi-freight__options">';
						$.each(res.options, function (i, opt) {
							var name = opt.companyName ? opt.companyName + ' — ' + opt.name : opt.name;
							var price = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(opt.shippingCost);
							var days = '';
							if (opt.deliveryTime && parseInt(opt.deliveryTime, 10) > 0) {
								days = ' · <span class="vavi-freight__days">' + opt.deliveryTime + (parseInt(opt.deliveryTime, 10) > 1 ? ' dias' : ' dia') + '</span>';
							}
							html += '<li class="vavi-freight__option"><span class="vavi-freight__name">' + $('<span>').text(name).html() + '</span><span class="vavi-freight__price">' + price + '</span>' + days + '</li>';
						});
						html += '</ul>';
						$result.html(html);
					})
					.fail(function () {
						$btn.prop('disabled', false).text(VAVI_WC.calculate);
						$result.html('<p class="vavi-freight__msg vavi-freight__msg--error">' + VAVI_WC.error + '</p>');
					});
			});

			// Enter no campo CEP dispara o cálculo.
			$cep.on('keydown', function (e) {
				if (e.key === 'Enter' || e.keyCode === 13) {
					e.preventDefault();
					$btn.trigger('click');
				}
			});
		});
	});
})(jQuery);
