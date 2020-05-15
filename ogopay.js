// console.log('ogopay.js loaded');
// if (ogopay_params) console.log(ogopay_params);

// create the zoid component that gets loaded inside the modal in an iframe
window.MyZoidComponent = zoid.create({

	// The html tag used to render my component
	tag: 'my-component',

	url: 'https://test-ipg.ogo.exchange/defaulthostedpage',
	// url: 'http://localhost:3000/defaulthostedpage',

	dimensions: {
		width: '100%',
		height: '100%'
	},

	autoResize: true,

	props: {
		merchantId: {
			type: 'string',
			required: true,
			queryParam: true
		},

		customerId: {
			type: 'string',
			required: true,
			queryParam: true
		},

		returnUrl: {
			type: 'string',
			required: true,
			queryParam: true
		},

		orderId: {
			type: 'string',
			required: true,
			queryParam: true
		},

		page: {
			type: 'string',
			required: false,
			queryParam: true
		},

		amount: {
			type: 'string',
			required: true,
			queryParam: true
		},

		time: {
			type: 'string',
			required: true,
			queryParam: true
		},

		hash: {
			type: 'string',
			required: true,
			queryParam: true
		}

	},

});

// prepare the modal to display iframe from the checkout page
var prepareForCheckout = function () {

	// get the orderId from the url after the # (this should work in the checkout page)
	var orderId = window.location.hash.substr(1).split('.')[1];

	// get the orderId from the order pay url (something like /ogopay/checkout/order-pay/125/)
	if (!orderId) {
		var splitpath = window.location.pathname.split('/');
		var validValues = splitpath.filter(Boolean);
		orderId = validValues[validValues.length - 1];
	}

	if (orderId) {
		jQuery.post(
			ogopay_params.url, // The hook URL
			{ orderId: orderId }, // get order details of this orderId
			function (response) {

				// console.log(response)
				// console.log('showing ogo dialog');

				// show the modal dialog
				jQuery('#myModal').css('display', 'block');

				// when the close button on the dialog is clicked...
				jQuery("#modalClose").on('click', function () {

					// hide the dialog
					jQuery('#myModal').css('display', 'none');

					// clear the contents of the iframe container when closing
					// else the iframes get rendered repeatedly on multiple clicks
					jQuery('#cont').empty();

					// this is needed to clear the #orderId on the address bar
					// console.log('going back');

					// reload the page to clear the block ui and initialize
					location.reload();
				});

				// render the iframe
				MyZoidComponent({
					merchantId: response.merchantId,
					customerId: response.customerId,
					returnUrl: response.returnUrl,
					page: 'zoid',
					orderId: response.orderId,
					amount: response.amount,
					time: response.time,
					hash: response.hash
				}).render('#cont');
			}
		);
	}
}

// prepare the modal to display iframe from the add payment method page
var prepareForAddCard = function () {
	// show the modal dialog
	jQuery('#myModal').css('display', 'block');

	// when the close button on the dialog is clicked
	jQuery("#modalClose").on('click', function () {

		// hide the dialog
		jQuery('#myModal').css('display', 'none');

		// clear the contents of the iframe container when closing
		// else the iframes get rendered repeatedly on multiple clicks
		jQuery('#cont').empty();

		// reload the page to clear the block ui and initialize
		location.reload();

	});

	// render the iframe
	MyZoidComponent({
		merchantId: ogopay_params.merchantId,
		customerId: ogopay_params.customerId,
		returnUrl: ogopay_params.returnUrl,
		page: 'zoid',
		orderId: ogopay_params.orderId,
		amount: ogopay_params.amount,
		time: ogopay_params.time,
		hash: ogopay_params.hash

	}).render('#cont');
}

// prepare and show the dialog depending on which page we are in
var showOgopayDialog = function () {

	if (ogopay_params.mode == 'checkout') {
		prepareForCheckout();

	} else if (ogopay_params.mode == 'add-card') {
		prepareForAddCard();
	}
	// console.log(ogopay_params);

	return false; // this is needed to keep woocommerce from submitting
};

// triggered when add payment method button is clicked on the payment methods page
jQuery(function ($) {
	var add_payment_form = $('#add_payment_method');
	add_payment_form.on('submit', function () {

		if ($('#wc-ogopay-payment-token-new').is(':checked')) {
			showOgopayDialog();
			return false;
		}
	});
});

jQuery(function ($) {
	var add_payment_form = $('#order_review');
	add_payment_form.on('submit', function () {

		if ($('#wc-ogopay-payment-token-new').is(':checked')) {
			showOgopayDialog();
			return false;
		}

	});
});


// jQuery(window).bind( 'hashchange', showOgopayDialog);

//this is to trigger showing the dialog on the checkout page
window.onhashchange = showOgopayDialog;

// this is the parent function that gets called when we get redirected inside the dialog to the close_modal page
function close_modal(url) {
	jQuery("#modalClose").click();
	window.location.replace(url);
}
