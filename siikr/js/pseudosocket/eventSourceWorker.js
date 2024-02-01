var SSE = function (url, options) {
    if (!(this instanceof SSE)) {
      return new SSE(url, options);
    }
  
    this.INITIALIZING = -1;
    this.CONNECTING = 0;
    this.OPEN = 1;
    this.CLOSED = 2;
    this.isShutDown = false;
  
    this.url = url;
  
    options = options || {};
    this.headers = options.headers || {};
    this.payload = options.payload !== undefined ? options.payload : '';
    this.method = options.method || (this.payload && 'POST' || 'GET');
  
    this.FIELD_SEPARATOR = ':';
    this.listeners = {};
  
    this.xhr = null;
    this.readyState = this.INITIALIZING;
    this.progress = 0;
    this.chunk = '';
  
    this.addEventListener = function (type, listener) {
      if (this.listeners[type] === undefined) {
        this.listeners[type] = [];
      }
  
      if (this.listeners[type].indexOf(listener) === -1) {
        this.listeners[type].push(listener);
      }
    };
  
    this.removeEventListener = function (type, listener) {
      if (this.listeners[type] === undefined) {
        return;
      }
  
      var filtered = [];
      this.listeners[type].forEach(function (element) {
        if (element !== listener) {
          filtered.push(element);
        }
      });
      if (filtered.length === 0) {
        delete this.listeners[type];
      } else {
        this.listeners[type] = filtered;
      }
    };
  
    this.dispatchEvent = function (e) {
      if (!e) {
        return true;
      }
      //console.log(e);
      e.source = this;
  
      var onHandler = 'on' + e.type;
      if (e.type == 'open') {
        this.isShutDown = false;
      }
      if (this.hasOwnProperty(onHandler)) {
        this[onHandler].call(this, e);
        if (e.defaultPrevented) {
          return false;
        }
      }
  
      if (this.listeners[e.type]) {
        return this.listeners[e.type].every(function (callback) {
          callback(e);
          return !e.defaultPrevented;
        });
      }
  
      return true;
    };
  
    this._setReadyState = function (state) {
      var event = new CustomEvent('readystatechange');
      event.readyState = state;
      this.readyState = state;
      this.dispatchEvent(event);
    };
  
    this._onStreamFailure = function (e) {
      this.dispatchEvent(new CustomEvent('error'));
      this.close();
    }
  
    this._onStreamProgress = function (e) {
      if (this.xhr.status !== 200) {
        this._onStreamFailure(e);
        return;
      }
  
      if (this.readyState == this.CONNECTING) {
        this.dispatchEvent(new CustomEvent('open'));
        this._setReadyState(this.OPEN);
      }
      if (this.xhr != null) {
        var data = this.xhr.responseText.substring(this.progress);
        this.progress += data.length;
        data.split(/(\r\n|\r|\n){2}/g).forEach(function (part) {
          if (part.trim().length === 0) {
            this.dispatchEvent(this._parseEventChunk(this.chunk.trim()));
            this.chunk = '';
          } else {
            this.chunk += part;
          }
        }.bind(this));
      }
    };
  
    this._onStreamLoaded = function (e) {
      this._onStreamProgress(e);
  
      // Parse the last chunk.
      this.dispatchEvent(this._parseEventChunk(this.chunk));
      this.chunk = '';
    };
  
    /**
     * Parse a received SSE event chunk into a constructed event object.
     */
    this._parseEventChunk = function (chunk) {
      if (!chunk || chunk.length === 0) {
        return null;
      }
  
      var e = { 'id': null, 'retry': null, 'data': '', 'event': 'message' };
      chunk.split(/\n|\r\n|\r/).forEach(function (line) {
        line = line.trimRight();
        var index = line.indexOf(this.FIELD_SEPARATOR);
        if (index <= 0) {
          // Line was either empty, or started with a separator and is a comment.
          // Either way, ignore.
          return;
        }
  
        var field = line.substring(0, index);
        if (!(field in e)) {
          return;
        }
  
        var value = line.substring(index + 1).trimLeft();
        if (field === 'data') {
          this.waitFor = 0;
          this.reconnectCount = 0;
          e[field] += value;
        } else {
          e[field] = value;
        }
      }.bind(this));
  
      var event = new CustomEvent(e.event);
      event.data = e.data;
      event.id = e.id;
      return event;
    };
  
    this._checkStreamClosed = function () {
      if (this.xhr.readyState === XMLHttpRequest.DONE) {
        this.close();//this._setReadyState(this.CLOSED);
      }
    };
  
    this.stream = function () {
      this._setReadyState(this.CONNECTING);
      this.xhr = new XMLHttpRequest();
      this.progress = 0;
      this.xhr.addEventListener('progress', this._onStreamProgress.bind(this));
      this.xhr.addEventListener('load', this._onStreamLoaded.bind(this));
      this.xhr.addEventListener('readystatechange', this._checkStreamClosed.bind(this));
      this.xhr.addEventListener('error', this._onStreamFailure.bind(this));
      this.xhr.addEventListener('abort', this._onStreamFailure.bind(this));
      this.xhr.open(this.method, this.url);
      for (var header in this.headers) {
        this.xhr.setRequestHeader(header, this.headers[header]);
      }
      this.xhr.send(this.payload);
    };
  
  
    //milliseconds
    this.reconnectMin = 2;
    this.reconnectMax = 10000;
    this.reconnectCount = 0;
    this.rate = 50;
  
    this.close = function () {
      if (this.readyState === this.CLOSED) {
        return;
      }
      if (this.xhr == null) {
        return;
      }
      this.xhr.abort();
      this.xhr = null;
      this._setReadyState(this.CLOSED);
  
      //only shutdown if explicitly requested
      if (!this.isShutDown) {
        setTimeout(() => {
          if (this.xhr == null) {
            this.waitFor = this.reconnectMax - this.reconnectMin;
            this.waitFor = this.waitFor / (this.rate / this.reconnectCount);//Math.min(this.reconnectMax, Math.pow(Math.max(this.reconnectMin, 2), this.reconnectCount));
            this.waitFor = Math.min(this.waitFor, this.reconnectMax);
            this.reconnectCount++;
            this.stream();
          }
        }, this.waitFor);
      }
    };
  
    this.shutDown = function () {
      if (!this.isShutDown) {
        this.isShutDown = true;
        this.close();
      }
    }
  };
  
  
  var urlEventSourceListenerMap =
  {
    /**
     * url : {
     *  eventSourceObj : (Object),
     *  responseTagMap : {
     *    (tag) : {
     *      queryObj: {queryFunction : (string), 
     *                 params : {
     *                  (associative array of function paramater names and arguments)
     *                 }}
     *      ports: [port, port, port]
     *     }
     *    (tag) : [ports]
     *  },
     * compressedResponseTagMap : {
     *  //compressed version of ResponseTagMap, referencing values in compressionIndices
     * }
     * compressionIndices : {
     *    responseTags : ["responseTag", "responseTag", "responseTag"], 
     *    interests : [],
     *    generalVals : ["val1", "val2", "val3"],
     *    paramKeys: ["key1", "key2", "key3"], 
     *    paramVals: ["val1", "val2", "val3"],
     *    qFunctions: []
     *  },
     * url : "same as key" 
     * }
     */
  };
  
  var keepAlive = false;
  
  /**
   * We want to avoid disconnecting and reconnecting
   * when there's no need to. We can expect that unnecessary
   * reconnects would occur if a user has, for example, 
   * multiple tabs open requesting the same resources. 
   * 
   * One tab would not know that the other tab has already created
   * an eventsource for the specified listener. 
   * 
   * To that end, we keep a list of the responseTags we're listening for, 
   * and associate them with the ports being listened on. 
   * We only reconnect if the responseTag is new. Otherwise, we just
   * add the port to the list of ports to notify (if it isn't already in that list as well).
   * 
   * Since responsetags are already unique in terms of the query they want to perform, we need
   * not know anything more than the responsetag, port, and baseEventsrcURL.
   */
  
  /**
   * Create an EventSource for each unique URL. 
   * To eacg EventSource, add a listener for auto-generated response-tag,
   * and have it call the user specified callback.
   *
   * @param {*} baseEventSrcURL 
   * @param {*} responseTag 
   */
  
  
  function registerWithEventSource_url(baseEventSrcURL, registrationObjectArray, port) {
    //check if eventsource already exists.
    //-if it doesn't create it
    //-if it does, check if it already contains an identical responseTag 
    // to the one we want to add.
    //  --if it does contain that responseTag, just add the port for it if it's a new port. 
    //  --if it doesn't contain that responseTag, add an appropriate entry to the responsetagCallbackMap 
    //    create a new EventSource object on all of the old entries + the new entry. Connect it.
    //    once it's connected, close the old object.
    var eventSourceContainer = urlEventSourceListenerMap[baseEventSrcURL];
    if (eventSourceContainer == undefined) {
      eventSourceContainer = {
        responseTagMap: {},
        eventSourceObj: undefined,
        compressedResponseTagMap: {},
        compressionIndices: {
          interests: []
        },
        url: baseEventSrcURL
      };
      urlEventSourceListenerMap[baseEventSrcURL] = eventSourceContainer;
      registrationObjectArray.forEach(registrationObject => {
        eventSourceContainer.responseTagMap[registrationObject.responseTag] = {
          eventPattern: registrationObject.eventPattern,
          queryObj: registrationObject.queryObj,
          workspace_id: registrationObject.workspace_id,
          ports: [port]
        }
      });
      var newEventSource = createNewEventSourceForURL(eventSourceContainer);
      //eventSourceContainer.eventSourceObj = newEventSource;
    } else {
      reinitializeIfContainsNew(eventSourceContainer, registrationObjectArray, port);
    }
  }
  
  function resetIndexes(url) {
    var ci = urlEventSourceListenerMap[url].compressionIndices;
    ci.values = [];
    ci.paramKeys = [];
    ci.qFunctions = [];
    ci.paramValues = [];
    ci.responseTags = [];
  }
  
  
  /*var responseTags = [];
  //deduplication indices (ad-hoc compression scheme 
  //to get around the limitations of eventSource spec)
  var values = []; 
   
  //deduplication of queryFunction requests
  var qFunctions = []; 
  //deduplication of queryObject params
  var paramKeys = [];
  var paramValues = [];*/
  
  function getCompressedEventPatt(str, storeIn) {
    var valSplit = str.split("!");
    for (var i = 0; i < valSplit.length; i++) {
      var v = valSplit[i];
      var indexedVal = storeIn.values.indexOf(v);
      if (indexedVal == -1) {
        storeIn.values.push(v);
        indexedVal = storeIn.values.length - 1;
      }
      valSplit[i] = indexedVal;
    }
    return valSplit.join("!");
  }
  
  function getCompressedQueryObj(queryObj, storeIn) {
    var params = queryObj != null ? queryObj.params : null;
    if (params != null) {
      var paramKs = [];
      var paramVs = [];
      for (var k of Object.keys(params)) {
        var pki = storeIn.paramKeys.indexOf(k);
        var pStringVal = typeof params[k] == "object" ? JSON.stringify(params[k]) : params[k]; //lazy hack
        var pvi = storeIn.paramValues.indexOf(pStringVal);
        if (pki == -1) {
          storeIn.paramKeys.push(k);
          pki = storeIn.paramKeys.length - 1;
        }
        if (pvi == -1) {
          storeIn.paramValues.push(pStringVal);
          pvi = storeIn.paramValues.length - 1;
        }
        paramKs.push(pki);
        paramVs.push(pvi);
      }
      var indexedParamObj = {
        k: paramKs,
        v: paramVs
      };
  
      var qFuncIndex = storeIn.values.indexOf(queryObj.queryFunction);
      if (qFuncIndex == -1) {
        storeIn.values.push(queryObj.queryFunction);
        qFuncIndex = storeIn.values.length - 1;
      }
      return {
        qf: qFuncIndex,
        p: indexedParamObj
      };
    } else return null;
  }
  
  function getResponseTagIndex(key, url) {
    var ci = urlEventSourceListenerMap[url].compressionIndices;
    var index = ci.responseTags.indexOf(key);
    if (index == -1) {
      ci.responseTags.push(key);
      index = ci.responseTags.length - 1;
    }
    return index;
  }
  
  var SSEList = [];
  
  function createNewEventSourceForURL(evntContainer) {
    var fullregistrationJSON = buildCompressedEventSourceRegistrationJSON(evntContainer.url);
    var newPayload = JSON.stringify(fullregistrationJSON);
    var newEventSource = new SSE(evntContainer.url,
      {
        headers: { 'Content-Type': 'application/json' },
        payload: newPayload
      });
  
    SSEList.push(newEventSource);
  
    console.log("new payload length: " + newPayload.length);
    Object.keys(evntContainer.responseTagMap).forEach(responseTag => {
      var responseTagContainer = evntContainer.responseTagMap[responseTag];
      newEventSource.onopen = () => {
        responseTagContainer.ports.forEach(port => {
          port.postMessage({ connectionAck: true });
        });
      }
  
      newEventSource.addEventListener(
        getResponseTagIndex(responseTag, evntContainer.url), (e) => {
          responseTagContainer.ports.forEach(port => {
            var unstrung = JSON.parse(e.data);
            unstrung.responseTag = evntContainer.compressionIndices.responseTags[unstrung.s_responseTag];
            //var e2 = JSON.stringify(e.data);
            port.postMessage(unstrung);
          });
        });
    });
    newEventSource.addEventListener("open", () => {
      if (evntContainer.eventSourceObj != undefined) {
        //shutdown the old eventsourceObj when the new one is opened. 
        //but only if the old one is not the same as the new one
        //(in other words, don't shut down if we received an "open"
        //event on recovery due to network error)
        if (evntContainer.eventSourceObj != newEventSource) {
          evntContainer.eventSourceObj.shutDown();
        }
      }
      evntContainer.eventSourceObj = newEventSource;
    })
  
    newEventSource.stream();
  
  
    /*newEventSource.onopen = (e) => {
     
    }*/
  
    return newEventSource;
  }
  
  function reinitializeIfContainsNew(evntContainer, registrationObjectArray, port) {
    var responseTagMap = evntContainer.responseTagMap;
    var reregistrationRequired = false;
    registrationObjectArray.forEach(regObj => {
      reregistrationRequired = addIfNew(responseTagMap, regObj, port) ? true : reregistrationRequired;
    });
    if (reregistrationRequired || evntContainer.eventSourceObj == undefined) {
      createNewEventSourceForURL(evntContainer);
    } else {
      port.postMessage({ connectionAck: true });
    }
  }
  
  /**
   * returns true if additions requiring reinitialization were made.
   * false otherwise 
   * @param {*} evntContainer 
   * @param {*} registrationObject 
   * @param {*} port 
   */
  function addIfNew(responseTagMap, registrationObject, port) {
    if (responseTagMap[registrationObject.responseTag] != undefined) {
      var portList = responseTagMap[registrationObject.responseTag].ports;
      if (portList.indexOf(port) == -1) {
        portList.push(port);
      }
      return false;
    } else {
      responseTagMap[registrationObject.responseTag] = {
        eventPattern: registrationObject.eventPattern,
        queryObj: registrationObject.queryObj,
        workspace_id: registrationObject.workspace_id,
        ports: [port]
      }
      return true;
    }
  }
  
  /**
   * If the input url contains no variables,
   * assigns the values to a variable called "interests". 
   * If the input url ends in "=", appends the values to it  
   * (allowing you to define your own variable)
   * @param {*} baseEventSrcURL 
   * @param {*} rtm 
   */
  function buildCompressedEventSourceRegistrationJSON(url) {
    var rtm = urlEventSourceListenerMap[url].responseTagMap;
    var ci = urlEventSourceListenerMap[url].compressionIndices;
    resetIndexes(url);
    ci.interests = [];
    Object.keys(rtm).forEach(key => {
      var responseTagIdx = getResponseTagIndex(key, url);
      var interestObj = {
        ep: getCompressedEventPatt(rtm[key].eventPattern, ci),
        rti: responseTagIdx,
        qo: getCompressedQueryObj(rtm[key].queryObj, ci),
        w: rtm[key].workspace_id
      }
      ci.interests.push(interestObj);
    });
    return {
      interests: ci.interests,
      pkl: ci.paramKeys,
      pvl: ci.paramValues,
      gvl: ci.values
    };
  }
  
  
  
  function keepAlivePoller() {
    if (keepAlive) {
      setTimeout(() => {
        //      console.log("checking if reconnect required");
        var reconnectCount = 0;
        Object.keys(urlEventSourceListenerMap).forEach((url) => {
          var currentEventSrc = urlEventSourceListenerMap[url].eventSourceObj;
          if (currentEventSrc == null || currentEventSrc.readyState == 2) { //if the eventSource has been closed
            currentEventSrc = createNewEventSourceForURL(urlEventSourceListenerMap[url]);
            console.log("reconnect was required for " + url);
          } else {
            currentEventSrc.stream();
          }
          reconnectCount++;
        });
        if (reconnectCount == 0) {
          //        console.log("everything's humming along."); 
        } else {
          console.log("manually reconnected to" + reconnectCount + " event sources");
        }
        keepAlivePoller();
      }, 1000); //every second
    }
  }
  
  /** 
   * @return true if the all of the listeners are already registered with the given eventsource url, 
   * false otherwise. 
   */
  function addNewListeners(baseEventSrcUrl, toRegister, port) {
    var exists = true;
  
    toRegister.forEach(e => {
      var result = addListenerIfNew(baseEventSrcUrl, e.response_tag, e.query_url, port);
      if (result == false) exists = false;
    });
    return exists;
  }
  
  /**
   * @return true if the listener is already registered with eventsource, false otherwise. 
   */
  function addListenerIfNew(baseEventSrcURL, response_tag, eventPattern, queryUrl, port) {
    var exists = true;
    var listenerMap = urlEventSourceListenerMap[baseEventSrcURL];
    if (listenerMap[response_tag]) {
      var l_ports = listenerMap["" + listenerKey].ports;
      if (l_ports.indexOf[port] == -1) {
        l_ports.push(ports);
      }
    } else {
      listenerMap["" + listenerKey] = {
        event_pattern: eventPattern,
        server_response_tag: responseTag,
        endpoint: endpoint_url,
        ports: [port]
      }
      exists = false;
    }
    return exists;
  }
  
  
  function removeAllEntriesExclusiveTo(port) {
    var totalDependents = 0;
    Object.keys(urlEventSourceListenerMap).forEach(url => {
      var nonExclusiveElems = 0;
      var evsrcObj = urlEventSourceListenerMap[url].eventSourceObj;
      var rtm = urlEventSourceListenerMap[url].responseTagMap;
      Object.keys(rtm).forEach(responseTag => {
        var ports = rtm[responseTag].ports;
        var idxof = ports.indexOf(port);
        if (idxof > -1) {
          ports.splice(idxof, 1);
        }
        if (ports.length > 0) {
          nonExclusiveElems++;
        } else {
          delete rtm[responseTag];
        }
      });
      if (nonExclusiveElems == 0 && evsrcObj != undefined) {
        setTimeout(() => { evsrcObj.shutDown(); }, 2000);
        delete evsrcObj;
      } else {
        totalDependents++;
      }
    });
    return totalDependents;
  }
  
  //if (SharedWorker != null) {
    onconnect = function (e) {
      var port = e.ports[0];
  
      port.onmessage = function (e) {
        if (e.data.register) {
          if (Object.keys(urlEventSourceListenerMap).length == 0) {
            keepAlivePoller();
          }
          removeAllEntriesExclusiveTo(port);
          registerWithEventSource_url(e.data.eventURL, e.data.listeners, port);
  
        } else if (e.data.close) {
          var totalDependents = removeAllEntriesExclusiveTo(port);
          if (totalDependents == 0) {
            keepAlive = false;
            console.log("killing all connections");
          }
          port.close();
        }
      };
    };
  //}
  //else {
    onmessage = function (e) {
      if (e.data.register) {
        if (Object.keys(urlEventSourceListenerMap).length == 0) {
          keepAlivePoller();
        }
        removeAllEntriesExclusiveTo(this);
        registerWithEventSource_url(e.data.eventURL, e.data.listeners, this);
  
      } else if (e.data.close) {
        var totalDependents = removeAllEntriesExclusiveTo(0);
        if (totalDependents == 0) {
          keepAlive = false;
          console.log("killing all connections");
        }        
      }
    };
  //}
  