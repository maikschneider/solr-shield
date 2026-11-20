// Solr Shield: copy of EXT:solr 13's facet_daterange_controller.js. Changes are marked "Solr Shield".
//
// The facet URL template arrives inside the signed `_ss` payload, which must
// not change, so the range travels next to it in `_sr[from]` and `_sr[to]`.
// Navigating through a link click instead of assigning window.location lets
// solr-shield.js attach `_solr_bot`.
function solrShieldFillUrl(template, values) {
  const url = new URL(template, window.location.href);
  if (url.searchParams.get('_ss') === null) {
    return Object.keys(values).reduce(
      (result, placeholder) => result.replace(placeholder, String(values[placeholder])),
      template
    );
  }
  url.searchParams.set('_sr[from]', String(values.___FROM___));
  url.searchParams.set('_sr[to]', String(values.___TO___));
  return url.toString();
}

function solrShieldNavigate(url) {
  const link = document.createElement('a');
  link.href = url;
  link.hidden = true;
  document.body.appendChild(link);
  link.click();
  link.remove();
}

/**
 * The Controller. Controller responds to user actions and
 * invokes changes on the model.
 */
function DateRangeFacetController() {
    var _this = this;

    this.init = function() {
        dateSelector = jQuery(".dateselector");
        dateSelector.datepicker();
        dateSelector.change(function(){ _this.solrRequest("created"); });
    };

    this.solrRequest = function(facetName) {
        startDate = jQuery('#start_date_'+facetName);
        endDate = jQuery('#end_date_'+facetName);
        // Solr Shield: a single date filters an open range, sent as an empty bound
        if (startDate.val() !== '' || endDate.val() !== '') {
            url = jQuery('#' + facetName + '_url').val();
            start_date = startDate.val() !== '' ? _this.convertToDate(startDate.datepicker('getDate')) + '0000' : '';
            end_date = endDate.val() !== '' ? _this.convertToDate(endDate.datepicker('getDate')) + '0000' : '';

            url = solrShieldFillUrl(url, { ___FROM___: start_date, ___TO___: end_date });
            solrShieldNavigate(url);
        }
    };

    this.convertToDate = function(date) {
        return jQuery.datepicker.formatDate('yymmdd', date);
    };
}


jQuery(document).ready(function() {
    var dateRangeFacetController = new DateRangeFacetController();
    dateRangeFacetController.init();

    jQuery("body").on("tx_solr_updated", function() {
        dateRangeFacetController.init();
    });
});
