import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
import EncodingHelper from '../helper/encoding.helper'
import PageLoadingIndicatorUtil from 'src/utility/loading-indicator/page-loading-indicator.util';

export default class LoginPlugin extends Plugin {

    static options = {
        loginChallengeUrl: window.router['frontend.account.webauthn.login.challenge'],
        loginVerifyUrl: window.router['frontend.account.webauthn.login.verify']
    };

    init() {
        this._getForm();

        if (!this._form) {
            throw new Error(`No form found for plugin: ${this.constructor.name}`);
        }

        this._client = new HttpClient(window.accessKey, window.contextToken);

        this._registerEvents();
    }

    /**
     * tries to get the closest form
     *
     * @returns {HTMLElement|boolean}
     * @private
     */
    _getForm() {
        if (this.el && this.el.nodeName === 'FORM') {
            this._form = this.el;
        } else {
            this._form = this.el.closest('form');
        }
    }

    /**
     * registers all needed events
     *
     * @private
     */
    _registerEvents() {
        this._form.addEventListener('submit', this._onLoginSubmit.bind(this));
    }

    /**
     * on submit callback for the form
     *
     * @param event
     *
     * @private
     */
    _onLoginSubmit(event) {
        const data = FormSerializeUtil.serializeJson(this._form);
        if (!data.useWebAuthn) {
            return; // Use regular password based login
        }

        event.preventDefault();
        PageLoadingIndicatorUtil.create();
        console.log('Requesting login options from server');
        this._sendRequest(this.options.loginChallengeUrl, {username: data.username}, this._getCredential.bind(this));
    }

    _getCredential(response) {
        const options = this._convertLoginOptions(response);

        console.log('Requesting credentials from authenticator', options);

        navigator.credentials.get({publicKey: options})
            .then(this._sendLoginRequest.bind(this), error => {
                PageLoadingIndicatorUtil.remove();
                console.log(error.toString()); // Example: timeout, interaction refused...
            });
    }

    _sendLoginRequest(authenticatorData) {
        const formData = FormSerializeUtil.serializeJson(this._form);
        const payload = {
            username: formData.username,
            credential: this._convertAuthenticatorData(authenticatorData)
        };

        console.log('Sending login request to server', payload);

        this._sendRequest(this.options.loginVerifyUrl, payload, this._onLoginSuccess.bind(this));
    }

    _onLoginSuccess(response) {
        const formData = FormSerializeUtil.serializeJson(this._form);
        if (formData.redirectPath) {
            window.location = formData.redirectPath;
        }
        PageLoadingIndicatorUtil.remove();
        console.log('handleLoginResponse', response);
    }

    _sendRequest(url, data, success) {
        const request = this._client.post(url, JSON.stringify(data), function() {});
        request.addEventListener('loadend', () => {
            if (request.status === 200) {
                success(JSON.parse(request.responseText));
            }
        });
    }

    _convertLoginOptions(options) {
        options.challenge = EncodingHelper.toByteArray(EncodingHelper.base64UrlDecode(options.challenge));
        if (options.allowCredentials) {
            options.allowCredentials = options.allowCredentials.map(function(data) {
                return {
                    ...data,
                    'id': EncodingHelper.toByteArray(EncodingHelper.base64UrlDecode(data.id))
                };
            });
        }

        return options;
    }

    _convertAuthenticatorData(data) {
        return {
            id: data.id,
            type: data.type,
            rawId: EncodingHelper.arrayToBase64String(new Uint8Array(data.rawId)),
            response: {
                authenticatorData: EncodingHelper.arrayToBase64String(new Uint8Array(data.response.authenticatorData)),
                clientDataJSON: EncodingHelper.arrayToBase64String(new Uint8Array(data.response.clientDataJSON)),
                signature: EncodingHelper.arrayToBase64String(new Uint8Array(data.response.signature)),
                userHandle: data.response.userHandle ? EncodingHelper.arrayToBase64String(new Uint8Array(data.response.userHandle)) : null
            }
        };
    }
}
