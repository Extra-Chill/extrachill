import React from 'react';
import './OpeningScreen.scss';

const OpeningScreen = ({ title, description, buttonText, onButtonClick, children }) => {
    return (
        <div className="ai-adventure-opening-screen">
            {title && <h2 className="ai-adventure-opening-title">{title}</h2>}
            {description && <p className="ai-adventure-opening-description">{description}</p>}
            {children}
            {buttonText && (
                <button className="ai-adventure-opening-button" onClick={onButtonClick}>{buttonText}</button>
            )}
        </div>
    );
};

export default OpeningScreen; 