$(document).ready(function () {



  // INITIALIZATION
  // ==============

  // Replace with your own values
  var APPLICATION_ID = 'G9K82IDUDX';
  var SEARCH_ONLY_API_KEY = '876286a34d35bf9c8b4a8d1398c22a6a';
  var INDEX_NAME = 'resumes';
  var PARAMS = {
    hitsPerPage: 20,
    maxValuesPerFacet: 5,
    facets: ['type'],
    disjunctiveFacets: ['category_en', 'location_en', 'job_level_en', 'most_recent_employer', 
						'suggested_salary', "updated_date",'exp_years_en', 'attached',
						'nationality_en','language1_name','language1_proficiency_en'],
    // numericFilters: 'updated_date>=1422359939'
  };
  var FACETS_SLIDER = ["suggested_salary", "updated_date"];
  var FACETS_ORDER_OF_DISPLAY = ['category_en', 'location_en', 'job_level_en', 'most_recent_employer', 'suggested_salary', 
								'updated_date','exp_years_en', 'attached','nationality_en','language1_name','language1_proficiency_en'];
  var FACETS_LABELS = {
    category_en: 'Category',
    location_en: 'Location',
    job_level_en: 'Job Level',
    most_recent_employer: 'Most recent employer',
    suggested_salary: 'Suggested Salary',
    updated_date: "Last Modified",
    exp_years_en: 'Years of Experience',
    attached: 'Resume Type',
	nationality_en: 'Nationality',
	language1_name: 'Language',
	language1_proficiency_en: 'Language Proficiency'
  };


  var sliders = {};

  // Client + Helper initialization
  var algolia = algoliasearch(APPLICATION_ID, SEARCH_ONLY_API_KEY);
  var algoliaHelper = algoliasearchHelper(algolia, INDEX_NAME, PARAMS);

  // DOM BINDING
  $searchInput = $('#search-input');
  $searchInputIcon = $('#search-input-icon');
  $main = $('main');
  $sortBySelect = $('#sort-by-select');
  $hits = $('#hits');
  $stats = $('#stats');
  $facets = $('#facets');
  $pagination = $('#pagination');

  // added by Ninh
  $lastModified = $('#last-modified');

  // Hogan templates binding
  var hitTemplate = Hogan.compile($('#hit-template').text());
  var statsTemplate = Hogan.compile($('#stats-template').text());
  var facetTemplate = Hogan.compile($('#facet-template').text());
  var sliderTemplate = Hogan.compile($('#slider-template').text());
  var paginationTemplate = Hogan.compile($('#pagination-template').text());
  var noResultsTemplate = Hogan.compile($('#no-results-template').text());

  // SEARCH BINDING
  // ==============

  // Input binding
  $searchInput
    .on('input propertychange', function (e) {
      var query = e.currentTarget.value;

      toggleIconEmptyInput(query);
      algoliaHelper.setQuery(query).search();
    })
    .focus();

  // Search errors
  algoliaHelper.on('error', function (error) {
    console.log(error);
  });

  // Update URL
  algoliaHelper.on('change', function (state) {
    setURLParams();
  });

  // Search results
  algoliaHelper.on('result', function (content, state) {
    renderStats(content);
    renderHits(content);
    renderFacets(content, state);
    bindSearchObjects(state);
    renderPagination(content);
    handleNoResults(content);
  });

  // Initial search
  initFromURLParams();
  algoliaHelper.search();

  // RENDER SEARCH COMPONENTS
  // ========================

  function renderStats(content) {
    var stats = {
      nbHits: accounting.formatNumber(content.nbHits),
      nbHits_plural: content.nbHits !== 1,
      processingTimeMS: content.processingTimeMS
    };
    $stats.html(statsTemplate.render(stats));
  }

  function renderHits(content) {
    var fields = ["most_recent_position", "exp_jobtitle", "desired_job_title", "resume_title", "content"];
    $.each(content.hits, function (i, item) {
      if (!content.hits[i].companyLogo || content.hits[i].companyLogo.length <= 0) {
        content.hits[i].companyLogo = 'http://www.php.company/img/placeholder-logo.png';
      }
      item.updated_date_label = moment.unix(item.updated_date).format("DD/MM/YYYY");
      item.suggested_salary_label = accounting.formatNumber(item.suggested_salary);
      var fi = -1;
      item.highLight = _.chain(item._highlightResult)
        .pickBy(function (o) {return o.matchedWords.length > 0;})
        .at(fields)
        .find(function (o, index) {
          fi = index;
          return o !== undefined;
        })
        .value();
      item.highLight = item._snippetResult[fields[fi]];
      item.highLight.field = fields[fi];
      if (item.highLight.field == "most_recent_position") {
        delete item.highLight;
      }
      // console.log(item.highLight);
    });

    $hits.html(hitTemplate.render(content));
  }

  function renderFacets(content, state) {
    var facetsHtml = '';
    for (var facetIndex = 0; facetIndex < FACETS_ORDER_OF_DISPLAY.length; ++facetIndex) {
      var facetName = FACETS_ORDER_OF_DISPLAY[facetIndex];
      var facetResult = content.getFacetByName(facetName);
      if (!facetResult) continue;
      var facetContent = {};

      // Slider facets
      if ($.inArray(facetName, FACETS_SLIDER) !== -1) {
        facetContent = {
          facet: facetName,
          title: FACETS_LABELS[facetName]
        };
        facetContent.min = facetResult.stats.min;
        facetContent.max = facetResult.stats.max;
        var from = state.getNumericRefinement(facetName, '>=') || facetContent.min;
        var to = state.getNumericRefinement(facetName, '<=') || facetContent.max;
        facetContent.from = Math.min(facetContent.max, Math.max(facetContent.min, from));
        facetContent.to = Math.min(facetContent.max, Math.max(facetContent.min, to));
        // console.log(facetContent);
        facetsHtml += sliderTemplate.render(facetContent);
      }
      else {// Conjunctive + Disjunctive facets
        facetContent = {
          facet: facetName,
          title: FACETS_LABELS[facetName],
          values: content.getFacetValues(facetName, {sortBy: ['isRefined:desc', 'count:desc']}),
          disjunctive: $.inArray(facetName, PARAMS.disjunctiveFacets) !== -1
        };
        facetContent.values.forEach(function (v) {
          v.countLabel = accounting.formatNumber(v.count);
        });
        if (facetContent.facet == "attached") {//custom code
          facetContent.values.forEach(function (v) {
            (v.name == "false") && (v.label = "Online");
            (v.name == "true") && (v.label = "Attached");
          });
        }
        if (facetContent.facet == "exp_years_en" || facetContent.facet == "job_level_en") {//custom code
          var weights = {
            "15+ years": 1,
            "10-15 years": 2,
            "5-10 years": 3,
            "2-5 years": 4,
            "1-2 years": 5,
            "0-1 year": 6,
            "No experience": 7,

            "President": 1,
            "Vice President": 2,
            "CEO": 3,
            "Director": 4,
            "Vice Director": 5,
            "Manager": 6,
            "Team Leader/Supervisor": 7,
            "Experienced (Non-Manager)": 8,
            "New Grad/Entry Level/Internship": 9
          };
          facetContent.values.forEach(function (v) {
            v.weight = weights[v.name];
          });
          facetContent.values = facetContent.values.sort(function (a, b) {return a.weight - b.weight;});
        }

        _.chain(facetContent.values)
        facetsHtml += facetTemplate.render(facetContent);
      }
    }
    $facets.html(facetsHtml);
  }

  function bindSearchObjects(state) {
    // Bind Sliders
    for (facetIndex = 0; facetIndex < FACETS_SLIDER.length; ++facetIndex) {
      var facetName = FACETS_SLIDER[facetIndex];
      var slider = $('#' + facetName + '-slider');
      var sliderOptions = {
        type: 'double',
        grid: true,
        min: slider.data('min'),
        max: slider.data('max'),
        from: slider.data('from'),
        to: slider.data('to'),
        prettify: function (num) {
          return '$' + parseInt(num, 10);
        },
        onFinish: function (data) {
          var lowerBound = state.getNumericRefinement(facetName, '>=');
          lowerBound = lowerBound && lowerBound[0] || data.min;
          if (data.from !== lowerBound) {
            algoliaHelper.removeNumericRefinement(facetName, '>=');
            algoliaHelper.addNumericRefinement(facetName, '>=', data.from).search();
          }
          var upperBound = state.getNumericRefinement(facetName, '<=');
          upperBound = upperBound && upperBound[0] || data.max;
          if (data.to !== upperBound) {
            algoliaHelper.removeNumericRefinement(facetName, '<=');
            algoliaHelper.addNumericRefinement(facetName, '<=', data.to).search();
          }
        }
      };

      if (facetName == "updated_date") {
        sliderOptions = {
          type: 'double',
          grid: true,
          min: slider.data('min'),
          max: slider.data('max'),
          from: slider.data('from'),
          to: slider.data('to'),
          step: 24 * 60 * 60 * 30,
          prettify: function (num) {
            return moment.unix(num).format("DD/MM/YYYY");
          },
          onFinish: function (data) {
            var lowerBound = state.getNumericRefinement(facetName, '>=');
            lowerBound = lowerBound && lowerBound[0] || data.min;
            if (data.from !== lowerBound) {
              algoliaHelper.removeNumericRefinement(facetName, '>=');
              algoliaHelper.addNumericRefinement(facetName, '>=', data.from).search();
            }
            var upperBound = state.getNumericRefinement(facetName, '<=');
            upperBound = upperBound && upperBound[0] || data.max;
            if (data.to !== upperBound) {
              algoliaHelper.removeNumericRefinement(facetName, '<=');
              algoliaHelper.addNumericRefinement(facetName, '<=', data.to).search();
            }
          }
        }
      }

      slider.ionRangeSlider(sliderOptions);
    }
  }

  function renderPagination(content) {
    var pages = [];
    if (content.page > 3) {
      pages.push({current: false, number: 1});
      pages.push({current: false, number: '...', disabled: true});
    }
    for (var p = content.page - 3; p < content.page + 3; ++p) {
      if (p < 0 || p >= content.nbPages) continue;
      pages.push({current: content.page === p, number: p + 1});
    }
    if (content.page + 3 < content.nbPages) {
      pages.push({current: false, number: '...', disabled: true});
      pages.push({current: false, number: content.nbPages});
    }
    var pagination = {
      pages: pages,
      prev_page: content.page > 0 ? content.page : false,
      next_page: content.page + 1 < content.nbPages ? content.page + 2 : false
    };
    $pagination.html(paginationTemplate.render(pagination));
  }


  // NO RESULTS
  // ==========

  function handleNoResults(content) {
    if (content.nbHits > 0) {
      $main.removeClass('no-results');
      return;
    }
    $main.addClass('no-results');

    var filters = [];
    var i;
    var j;
    for (i in algoliaHelper.state.facetsRefinements) {
      filters.push({
        class: 'toggle-refine',
        facet: i, facet_value: algoliaHelper.state.facetsRefinements[i],
        label: FACETS_LABELS[i] + ': ',
        label_value: algoliaHelper.state.facetsRefinements[i]
      });
    }
    for (i in algoliaHelper.state.disjunctiveFacetsRefinements) {
      for (j in algoliaHelper.state.disjunctiveFacetsRefinements[i]) {
        filters.push({
          class: 'toggle-refine',
          facet: i,
          facet_value: algoliaHelper.state.disjunctiveFacetsRefinements[i][j],
          label: FACETS_LABELS[i] + ': ',
          label_value: algoliaHelper.state.disjunctiveFacetsRefinements[i][j]
        });
      }
    }
    for (i in algoliaHelper.state.numericRefinements) {
      for (j in algoliaHelper.state.numericRefinements[i]) {
        filters.push({
          class: 'remove-numeric-refine',
          facet: i,
          facet_value: j,
          label: FACETS_LABELS[i] + ' ',
          label_value: j + ' ' + algoliaHelper.state.numericRefinements[i][j]
        });
      }
    }
    $hits.html(noResultsTemplate.render({query: content.query, filters: filters}));
  }


  // EVENTS BINDING
  // ==============

  $(document).on('click', '.toggle-refine', function (e) {
    e.preventDefault();
    algoliaHelper.toggleRefine($(this).data('facet'), $(this).data('value')).search();
  });
  $(document).on('click', '.go-to-page', function (e) {
    e.preventDefault();
    $('html, body').animate({scrollTop: 0}, '500', 'swing');
    algoliaHelper.setCurrentPage(+$(this).data('page') - 1).search();
  });
  $sortBySelect.on('change', function (e) {
    e.preventDefault();
    algoliaHelper.setIndex(INDEX_NAME + $(this).val()).search();
  });
  $searchInputIcon.on('click', function (e) {
    e.preventDefault();
    $searchInput.val('').keyup().focus();
  });
  $(document).on('click', '.remove-numeric-refine', function (e) {
    e.preventDefault();
    algoliaHelper.removeNumericRefinement($(this).data('facet'), $(this).data('value')).search();
  });
  $(document).on('click', '.clear-all', function (e) {
    e.preventDefault();
    $searchInput.val('').focus();
    algoliaHelper.setQuery('').clearRefinements().search();
  });
  // Added by Ninh
  // $lastModified.on('change', function (e) {
  //   console.log('lastModified on change');
  //   //e.preventDefault();
  //
  //   //facetName = 'updated_date';
  //   lastModifiedSelectVal = parseInt($("#last-modified option:selected").val());
  //   //alert($lastModifiedSelectVal);
  //   if (lastModifiedSelectVal > -1) {
  //     lastModified4Search = ($.now() / 1000) - (lastModifiedSelectVal * 24 * 3600);
  //     //algoliaHelper.removeNumericFilters('updated_date', '>=');
  //     algoliaHelper.numericFilters = 'updated_date >= ' + lastModified4Search;
  //   }
  //   else {
  //     //alert('Not search by date'+PARAMS.numericFilters);
  //     //algoliaHelper.removeNumericRefinement('updated_date', '>=');
  //     algoliaHelper.numericFilters = '';
  //   }
  //   setURLParams();
  //   algoliaHelper.search();
  //   console.log('lastModified on change ended');
  // });


  // URL MANAGEMENT
  // ==============

  function initFromURLParams() {
    var URLString = window.location.search.slice(1);
    var URLParams = algoliasearchHelper.url.getStateFromQueryString(URLString);
    if (URLParams.query) $searchInput.val(URLParams.query);
    if (URLParams.index) $sortBySelect.val(URLParams.index.replace(INDEX_NAME, ''));
    algoliaHelper.overrideStateWithoutTriggeringChangeEvent(algoliaHelper.state.setQueryParameters(URLParams));
  }

  var URLHistoryTimer = Date.now();
  var URLHistoryThreshold = 700;

  function setURLParams() {
    var trackedParameters = ['attribute:*'];
    if (algoliaHelper.state.query.trim() !== '')  trackedParameters.push('query');
    if (algoliaHelper.state.page !== 0)           trackedParameters.push('page');
    if (algoliaHelper.state.index !== INDEX_NAME) trackedParameters.push('index');

    var URLParams = window.location.search.slice(1);
    var nonAlgoliaURLParams = algoliasearchHelper.url.getUnrecognizedParametersInQueryString(URLParams);
    var nonAlgoliaURLHash = window.location.hash;
    var helperParams = algoliaHelper.getStateAsQueryString({
      filters: trackedParameters,
      moreAttributes: nonAlgoliaURLParams
    });
    if (URLParams === helperParams) return;

    var now = Date.now();
    if (URLHistoryTimer > now) {
      window.history.replaceState(null, '', '?' + helperParams + nonAlgoliaURLHash);
    }
    else {
      window.history.pushState(null, '', '?' + helperParams + nonAlgoliaURLHash);
    }
    URLHistoryTimer = now + URLHistoryThreshold;
  }

  window.addEventListener('popstate', function () {
    initFromURLParams();
    algoliaHelper.search();
  });


  // HELPER METHODS
  // ==============

  function toggleIconEmptyInput(query) {
    $searchInputIcon.toggleClass('empty', query.trim() !== '');
  }

/// TOOLTIP
  function tooltip() {
    $('[data-toggle="tooltip"]').tooltip({html: true});
  };
  setTimeout(tooltip, 1000);

});
